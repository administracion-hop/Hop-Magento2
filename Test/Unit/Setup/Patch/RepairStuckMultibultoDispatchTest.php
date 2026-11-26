<?php

declare(strict_types=1);

namespace Hop\Envios\Test\Unit\Setup\Patch;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\OrderGrid\DispatchStatusExpression as Status;
use Hop\Envios\Setup\Patch\Data\RepairStuckMultibultoDispatch;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * Corre el parche sobre una base SQLite en memoria. El UPDATE de hop_envios se ejecuta tal cual;
 * el de la grilla (UPDATE con JOIN, que SQLite no tiene) se ejecuta con el mismo CASE de la
 * columna sobre los pedidos que el parche pidió recalcular. Que MySQL lo interprete igual se
 * confirma en la prueba sobre Magento.
 */
class RepairStuckMultibultoDispatchTest extends TestCase
{
    /** @var \PDO */
    private $db;

    /** @var string */
    private $expression;

    /** @var RepairStuckMultibultoDispatch */
    private $patch;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Falta la extensión pdo_sqlite.');
        }
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE sales_order (entity_id INTEGER PRIMARY KEY, shipping_method TEXT)');
        $this->db->exec('CREATE TABLE sales_order_grid (entity_id INTEGER PRIMARY KEY, hop_dispatch_status TEXT)');
        $this->db->exec(
            'CREATE TABLE hop_envios (entity_id INTEGER PRIMARY KEY, order_id INTEGER, status_shipment TEXT,'
            . ' last_error TEXT, last_error_code TEXT, next_retry_at TEXT)'
        );
        $this->db->exec(
            'CREATE TABLE hop_envios_shipment (entity_id INTEGER PRIMARY KEY AUTOINCREMENT, hop_envio_id INTEGER,'
            . ' shipment_id INTEGER, status TEXT, tracking_nro TEXT)'
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);
        $status = new Status($resource);
        $this->expression = (string)$status;

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(fn ($name) => '"' . $name . '"');
        $connection->method('fetchCol')->willReturnCallback(function ($sql, $bind = []) {
            $statement = $this->db->prepare((string)$sql);
            $statement->execute($bind);
            return $statement->fetchAll(\PDO::FETCH_COLUMN);
        });
        $connection->method('fetchAll')->willReturnCallback(function ($sql, $bind = []) {
            $statement = $this->db->prepare((string)$sql);
            $statement->execute($bind);
            return $statement->fetchAll(\PDO::FETCH_ASSOC);
        });
        $connection->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $sql = (string)$sql;
            if (strpos($sql, 'sales_order_grid') !== false) {
                $this->assertMatchesRegularExpression('/IN \(([\d,]+)\)$/', $sql);
                preg_match('/IN \(([\d,]+)\)$/', $sql, $match);
                $sql = 'UPDATE sales_order_grid SET hop_dispatch_status = (SELECT ' . $this->expression
                    . ' FROM sales_order WHERE sales_order.entity_id = sales_order_grid.entity_id)'
                    . ' WHERE entity_id IN (' . $match[1] . ')';
            }
            $statement = $this->db->prepare($sql);
            $statement->execute($bind);
            return $statement;
        });

        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        $this->patch = new RepairStuckMultibultoDispatch($setup, $status);
    }

    /**
     * @param int $orderId
     * @param string $status status_shipment del despacho
     * @param string|null $code last_error_code
     * @param string[] $bultos estado de cada bulto en hop_envios_shipment
     * @param string $gridValue valor que tenía la grilla antes del parche
     */
    private function order(int $orderId, string $status, ?string $code, array $bultos, string $gridValue): void
    {
        $this->db->exec("INSERT INTO sales_order VALUES ({$orderId}, 'hop_hop')");
        $this->db->prepare('INSERT INTO sales_order_grid VALUES (?, ?)')->execute([$orderId, $gridValue]);
        $this->db->prepare('INSERT INTO hop_envios VALUES (?, ?, ?, NULL, ?, NULL)')
            ->execute([$orderId, $orderId, $status, $code]);
        foreach ($bultos as $i => $bultoStatus) {
            $tracking = $bultoStatus === 'completed' ? 'HOP' . $i : null;
            $this->db->prepare(
                'INSERT INTO hop_envios_shipment (hop_envio_id, shipment_id, status, tracking_nro) VALUES (?, ?, ?, ?)'
            )->execute([$orderId, $orderId * 10 + $i, $bultoStatus, $tracking]);
        }
    }

    private function row(int $orderId): array
    {
        return $this->db->query(
            "SELECT h.status_shipment, h.last_error_code, h.last_error, g.hop_dispatch_status FROM hop_envios h"
            . " JOIN sales_order_grid g ON g.entity_id = h.order_id WHERE h.order_id = {$orderId}"
        )->fetch(\PDO::FETCH_ASSOC);
    }

    public function testStuckMultibultoBecomesPartial(): void
    {
        // Como IS000000051: bulto 1 aceptado, bulto 2 rechazado, despacho pendiente.
        $this->order(1, 'pending', null, ['completed', 'failed'], Status::PENDING);
        $this->order(2, 'processing', null, ['failed', 'failed'], Status::PENDING);
        // Un envío agregado después del despacho no cuenta como bulto mandado.
        $this->order(3, 'pending', null, ['completed', 'failed', 'unsupported'], Status::PENDING);
        $expected = [
            1 => 'Ya creado en Hop con bultos rechazados: 1 de 2 bultos rechazados por Hop.',
            2 => 'Ya creado en Hop con bultos rechazados: 2 de 2 bultos rechazados por Hop.',
            3 => 'Ya creado en Hop con bultos rechazados: 1 de 2 bultos rechazados por Hop.',
        ];

        $this->patch->apply();

        foreach ($expected as $orderId => $message) {
            $row = $this->row($orderId);
            $this->assertSame('failed', $row['status_shipment']);
            $this->assertSame(DispatchError::CODE_MULTIBULTO_PARTIAL, $row['last_error_code']);
            $this->assertSame($message, $row['last_error'], 'el mismo motivo que deja el despacho');
            $this->assertSame(
                Status::PARTIAL,
                $row['hop_dispatch_status'],
                'la grilla pasa a Parcial sin guardar el pedido'
            );
        }
    }

    /**
     * @return array [estado, código, bultos, valor de la grilla]
     */
    public static function untouchedProvider(): array
    {
        return [
            'pendiente sin bultos' => ['pending', null, [], Status::PENDING],
            'pendiente con todos los bultos aceptados' => [
                'pending',
                null,
                ['completed', 'completed'],
                Status::PENDING,
            ],
            'ya parcial' => [
                'failed',
                DispatchError::CODE_MULTIBULTO_PARTIAL,
                ['completed', 'failed'],
                Status::PARTIAL,
            ],
            'despachado con un bulto agregado después' => [
                'completed',
                null,
                ['completed', 'unsupported'],
                Status::DISPATCHED,
            ],
            'error al despachar' => ['failed', 'credentials', [], Status::FAILED],
        ];
    }

    /**
     * @dataProvider untouchedProvider
     */
    public function testOtherDispatchesAreNotTouched(
        string $status,
        ?string $code,
        array $bultos,
        string $gridValue
    ): void {
        $this->order(1, $status, $code, $bultos, $gridValue);
        $before = $this->row(1);

        $this->patch->apply();

        $this->assertSame($before, $this->row(1));
    }

    public function testRunningTwiceGivesTheSameResult(): void
    {
        $this->order(1, 'pending', null, ['completed', 'failed'], Status::PENDING);

        $this->patch->apply();
        $first = $this->row(1);
        $this->patch->apply();

        $this->assertSame($first, $this->row(1));
    }
}
