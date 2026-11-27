<?php

declare(strict_types=1);

namespace Hop\Envios\Test\Unit\Setup\Patch;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\OrderGrid\DispatchStatusExpression as Status;
use Hop\Envios\Setup\Patch\Data\ReopenDispatchWithoutHopRecord;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;

/**
 * Corre el parche sobre una base SQLite en memoria (mismo armado que
 * RepairStuckMultibultoDispatchTest): el UPDATE de hop_envios tal cual, y el de la grilla con el
 * mismo CASE de la columna sobre los pedidos que el parche pidió recalcular.
 */
class ReopenDispatchWithoutHopRecordTest extends TestCase
{
    /** @var \PDO */
    private $db;

    /** @var string */
    private $expression;

    /** @var ReopenDispatchWithoutHopRecord */
    private $patch;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Falta la extensión pdo_sqlite.');
        }
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE sales_order (entity_id INTEGER PRIMARY KEY, shipping_method TEXT, state TEXT)');
        $this->db->exec('CREATE TABLE sales_order_grid (entity_id INTEGER PRIMARY KEY, hop_dispatch_status TEXT)');
        $this->db->exec('CREATE TABLE sales_shipment (entity_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER)');
        $this->db->exec(
            'CREATE TABLE hop_envios (entity_id INTEGER PRIMARY KEY, order_id INTEGER, info_hop TEXT,'
            . ' status_shipment TEXT, last_error TEXT, last_error_code TEXT, next_retry_at TEXT)'
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

        $this->patch = new ReopenDispatchWithoutHopRecord($setup, $status);
    }

    /**
     * Crea un pedido Hop con su despacho, sus envíos de Magento y sus bultos.
     *
     * @param int $orderId
     * @param string $status status_shipment del despacho
     * @param string|null $infoHop respuesta de Hop guardada
     * @param int $shipments envíos de Magento del pedido
     * @param string[] $bultos estado de cada bulto en hop_envios_shipment
     * @param string $gridValue valor que tenía la grilla antes del parche
     */
    private function order(
        int $orderId,
        string $status,
        ?string $infoHop,
        int $shipments,
        array $bultos,
        string $gridValue,
        string $state = 'processing'
    ): void {
        $this->db->prepare("INSERT INTO sales_order VALUES (?, 'hop_hop', ?)")->execute([$orderId, $state]);
        $this->db->prepare('INSERT INTO sales_order_grid VALUES (?, ?)')->execute([$orderId, $gridValue]);
        $this->db->prepare('INSERT INTO hop_envios VALUES (?, ?, ?, ?, NULL, NULL, NULL)')
            ->execute([$orderId, $orderId, $infoHop, $status]);
        for ($i = 0; $i < $shipments; $i++) {
            $this->db->prepare('INSERT INTO sales_shipment (order_id) VALUES (?)')->execute([$orderId]);
        }
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
            'SELECT h.status_shipment, h.last_error_code, h.last_error, h.next_retry_at, g.hop_dispatch_status'
            . " FROM hop_envios h JOIN sales_order_grid g ON g.entity_id = h.order_id WHERE h.order_id = {$orderId}"
        )->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array [respuesta de Hop guardada, envíos de Magento]
     */
    public static function withoutHopRecordProvider(): array
    {
        return [
            'multibulto rechazado entero (sin respuesta guardada)' => [null, 2],
            'respuesta vacía' => ['', 2],
            'un solo envío' => [null, 1],
        ];
    }

    /**
     * @dataProvider withoutHopRecordProvider
     */
    public function testDispatchWithoutHopRecordIsReopened(?string $infoHop, int $shipments): void
    {
        $this->order(1, 'completed', $infoHop, $shipments, [], Status::DISPATCHED);

        $this->patch->apply();

        $row = $this->row(1);
        $this->assertSame('failed', $row['status_shipment']);
        $this->assertSame(DispatchError::CODE_UNCONFIRMED_DISPATCH, $row['last_error_code']);
        $this->assertTrue(
            DispatchError::isRetryable($row['last_error_code']),
            'se puede reintentar con "Enviar a HOP"'
        );
        $this->assertSame(DispatchError::unconfirmedDispatchMessage(), $row['last_error']);
        $this->assertNull($row['next_retry_at'], 'no se reintenta solo');
        $this->assertNull(
            DispatchError::retryDelayMinutes(1, null, $row['last_error_code']),
            'tampoco lo agenda un fallo posterior con este código'
        );
        $this->assertSame(
            Status::FAILED,
            $row['hop_dispatch_status'],
            'la grilla pasa a "Error al despachar" sin guardar el pedido'
        );
    }

    /**
     * @return array [estado, respuesta de Hop, envíos de Magento, bultos, valor de la grilla]
     */
    public static function untouchedProvider(): array
    {
        return [
            'un envío despachado (respuesta y bulto)' => [
                'completed', '{"tracking_nro":"1"}', 1, ['completed'], Status::DISPATCHED,
            ],
            'multibulto despachado (bultos sin respuesta)' => [
                'completed', null, 2, ['completed', 'completed'], Status::DISPATCHED,
            ],
            'despachado con un envío agregado después' => [
                'completed', null, 2, ['completed', 'unsupported'], Status::DISPATCHED,
            ],
            'despacho directo sin envío de Magento' => [
                'completed', '{"tracking_nro":"1"}', 0, [], Status::DISPATCHED,
            ],
            'despachado sin envíos ni respuesta (sin envío de Magento)' => [
                'completed', null, 0, [], Status::DISPATCHED,
            ],
            'pendiente sin rastro de Hop' => ['pending', null, 1, [], Status::PENDING],
            'rechazado entero con una versión posterior a la 18065' => ['failed', null, 2, [], Status::FAILED],
        ];
    }

    /**
     * @dataProvider untouchedProvider
     */
    public function testOtherDispatchesAreNotTouched(
        string $status,
        ?string $infoHop,
        int $shipments,
        array $bultos,
        string $gridValue
    ): void {
        $this->order(1, $status, $infoHop, $shipments, $bultos, $gridValue);
        $before = $this->row(1);

        $this->patch->apply();

        $this->assertSame($before, $this->row(1));
    }

    /**
     * @return array [estado del pedido]
     */
    public static function closedOrderProvider(): array
    {
        return ['cancelado' => ['canceled'], 'cerrado (reembolsado)' => ['closed']];
    }

    /**
     * Un pedido cancelado o cerrado no se va a despachar: no pasa a "Error al despachar".
     *
     * @dataProvider closedOrderProvider
     */
    public function testCanceledOrClosedOrdersAreNotTouched(string $state): void
    {
        $this->order(1, 'completed', null, 2, [], Status::DISPATCHED, $state);
        $before = $this->row(1);

        $this->patch->apply();

        $this->assertSame($before, $this->row(1));
    }

    public function testRunningTwiceGivesTheSameResult(): void
    {
        $this->order(1, 'completed', null, 2, [], Status::DISPATCHED);

        $this->patch->apply();
        $first = $this->row(1);
        $this->patch->apply();

        $this->assertSame($first, $this->row(1));
    }
}
