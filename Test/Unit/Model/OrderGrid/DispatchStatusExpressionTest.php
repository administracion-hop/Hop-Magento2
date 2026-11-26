<?php

declare(strict_types=1);

namespace Hop\Envios\Test\Unit\Model\OrderGrid;

use Hop\Envios\Model\OrderGrid\DispatchStatusExpression as Status;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;

/**
 * Ejecuta el CASE real de la columna "Despacho Hop" sobre una base SQLite en memoria, con las
 * columnas de sales_order y hop_envios que usa. Prueba la lógica de cada valor; que MySQL lo
 * interprete igual se confirma en la prueba sobre Magento.
 */
class DispatchStatusExpressionTest extends TestCase
{
    /**
     * @var \PDO
     */
    private $db;

    /**
     * @var string
     */
    private $expression;

    /**
     * @var int
     */
    private $nextOrderId = 1;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('Falta la extensión pdo_sqlite.');
        }
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE sales_order (entity_id INTEGER PRIMARY KEY, shipping_method TEXT)');
        $this->db->exec(
            'CREATE TABLE hop_envios (entity_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER,'
            . ' status_shipment TEXT, last_error_code TEXT, next_retry_at TEXT)'
        );

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->expression = (string)new Status($resource);
    }

    /**
     * @return array[] [método de envío, filas de hop_envios [status, código, próximo reintento], valor esperado]
     */
    public static function casesProvider(): array
    {
        return [
            'otro método de envío' => ['flatrate_flatrate', [], Status::NOT_APPLICABLE],
            'sin método de envío' => [null, [], Status::NOT_APPLICABLE],
            'otro método con un fallo Hop viejo' => [
                'flatrate_flatrate',
                [['failed', 'origin_zip', null]],
                Status::NOT_APPLICABLE,
            ],
            'Hop sin fila en hop_envios' => ['hop_hop', [], Status::PENDING],
            'pendiente' => ['hop_hop', [['pending', null, null]], Status::PENDING],
            'en proceso' => ['hop_hop', [['processing', null, null]], Status::PENDING],
            'en proceso con un error anterior' => [
                'hop_hop',
                [['processing', 'reference_in_use', null]],
                Status::PENDING,
            ],
            'despachado' => ['hop_hop', [['completed', null, null]], Status::DISPATCHED],
            'multibulto parcial' => ['hop_hop', [['failed', 'multibulto_partial', null]], Status::PARTIAL],
            'referencia ya en uso' => ['hop_hop', [['failed', 'reference_in_use', null]], Status::EXISTS_IN_HOP],
            'credenciales rechazadas' => ['hop_hop', [['failed', 'credentials', null]], Status::FAILED],
            'credenciales, reintento en curso (código borrado)' => [
                'hop_hop',
                [['failed', null, null]],
                Status::FAILED,
            ],
            'error del servidor con reintento agendado' => [
                'hop_hop',
                [['failed', 'other', '2026-11-18 12:00:00']],
                Status::FAILED,
            ],
            'datos del pedido' => ['hop_hop', [['failed', 'origin_zip', null]], Status::FAILED],
            'fila duplicada: una completada gana' => [
                'hop_hop',
                [['failed', 'reference_in_use', null], ['completed', null, null]],
                Status::DISPATCHED,
            ],
            'fila duplicada: ya existe gana a error' => [
                'hop_hop',
                [['failed', 'credentials', null], ['failed', 'reference_in_use', null]],
                Status::EXISTS_IN_HOP,
            ],
        ];
    }

    /**
     * @dataProvider casesProvider
     * @param string|null $method
     * @param array $rows
     * @param string $expected
     */
    public function testResolvesEachCase($method, array $rows, string $expected): void
    {
        $orderId = $this->createOrder($method, $rows);
        $this->assertSame($expected, $this->statusOf($orderId));
    }

    public function testFailedOrderBecomesDispatchedWhenRetrySucceeds(): void
    {
        $orderId = $this->createOrder('hop_hop', [['failed', 'credentials', null]]);
        $this->assertSame(Status::FAILED, $this->statusOf($orderId));

        $this->db->exec(
            "UPDATE hop_envios SET status_shipment = 'completed', last_error_code = NULL WHERE order_id = {$orderId}"
        );
        $this->assertSame(Status::DISPATCHED, $this->statusOf($orderId));
    }

    public function testFollowsTheLastFailure(): void
    {
        $orderId = $this->createOrder('hop_hop', [['failed', 'credentials', null]]);

        $this->db->exec("UPDATE hop_envios SET last_error_code = 'reference_in_use' WHERE order_id = {$orderId}");
        $this->assertSame(Status::EXISTS_IN_HOP, $this->statusOf($orderId));

        $this->db->exec("UPDATE hop_envios SET last_error_code = 'origin_zip' WHERE order_id = {$orderId}");
        $this->assertSame(Status::FAILED, $this->statusOf($orderId));
    }

    public function testValuesFitTheGridColumn(): void
    {
        // sales_order_grid.hop_dispatch_status es varchar(20) (etc/db_schema.xml)
        $values = [
            Status::DISPATCHED,
            Status::PARTIAL,
            Status::EXISTS_IN_HOP,
            Status::FAILED,
            Status::PENDING,
            Status::NOT_APPLICABLE,
        ];
        foreach ($values as $value) {
            $this->assertLessThanOrEqual(20, strlen($value), $value);
        }
    }

    /**
     * @param string|null $method
     * @param array $rows
     * @return int
     */
    private function createOrder($method, array $rows): int
    {
        $orderId = $this->nextOrderId++;
        $this->db->prepare('INSERT INTO sales_order (entity_id, shipping_method) VALUES (?, ?)')
            ->execute([$orderId, $method]);
        $insert = $this->db->prepare(
            'INSERT INTO hop_envios (order_id, status_shipment, last_error_code, next_retry_at) VALUES (?, ?, ?, ?)'
        );
        foreach ($rows as [$status, $code, $retryAt]) {
            $insert->execute([$orderId, $status, $code, $retryAt]);
        }
        return $orderId;
    }

    private function statusOf(int $orderId): string
    {
        $statement = $this->db->prepare("SELECT {$this->expression} FROM sales_order WHERE entity_id = ?");
        $statement->execute([$orderId]);
        return (string)$statement->fetchColumn();
    }
}
