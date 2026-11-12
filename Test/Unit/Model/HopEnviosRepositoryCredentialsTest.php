<?php

namespace Hop\Envios\Test\Unit\Model;

use Hop\Envios\Cron\GenarateShipment;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosFactory;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\ResourceModel\HopEnvios as HopEnviosResource;
use Hop\Envios\Model\ResourceModel\HopEnvios\CollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\ResourceModel\GridInterface;
use PHPUnit\Framework\TestCase;

/**
 * Motivo propio para el rechazo de credenciales y selección de pedidos a reintentar.
 */
class HopEnviosRepositoryCredentialsTest extends TestCase
{
    /** @var array condiciones where() de la consulta */
    private $where = [];

    /** @var array ids que devuelve la consulta */
    private $ids = [];

    /** @var array updates hechos */
    private $updates = [];

    /** @var HopEnviosRepository */
    private $repository;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select) {
            $this->where[$condition] = $value;
            return $select;
        });
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturnCallback(fn () => $this->ids);
        $connection->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$data, $where];
            return count($this->ids);
        });

        $resource = $this->createMock(HopEnviosResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('hop_envios');
        $resource->method('getTable')->willReturnArgument(0);

        $this->repository = new HopEnviosRepository(
            $this->createMock(CollectionFactory::class),
            $this->createMock(HopEnviosFactory::class),
            $resource,
            $this->createMock(GridInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function hopEnvios(int $attempts = 0): HopEnvios
    {
        $model = $this->getMockBuilder(HopEnvios::class)->disableOriginalConstructor()->onlyMethods(['isObjectNew', 'dataHasChangedFor'])->getMock();
        $model->setData(['attempts' => $attempts, 'order_id' => 10]);
        return $model;
    }

    public function testCredentialsFailureHasOwnCodeAndDoesNotCount(): void
    {
        $hopEnvios = $this->hopEnvios(1);
        $this->repository->markFailed($hopEnvios, 'Hubo un error al enviar su pedido a Hop: Authorization fail.', 401);

        $this->assertSame(DispatchError::CODE_CREDENTIALS, $hopEnvios->getLastErrorCode());
        $this->assertSame(1, $hopEnvios->getAttempts(), 'no cuenta para los escalones de 1, 10 y 30 minutos');
        $this->assertNull($hopEnvios->getNextRetryAt(), 'espera a que las credenciales vuelvan a funcionar');
        $this->assertSame(GenarateShipment::SHIPMENT_STATUS_FAILED, $hopEnvios->getStatusShipment());
    }

    public function testServerErrorKeepsCurrentRetry(): void
    {
        $hopEnvios = $this->hopEnvios(0);
        $this->repository->markFailed($hopEnvios, 'Sin respuesta de la API de Hop (error de conexión o timeout).', 0);

        $this->assertNull($hopEnvios->getLastErrorCode());
        $this->assertSame(1, $hopEnvios->getAttempts());
        $this->assertNotNull($hopEnvios->getNextRetryAt(), 'Hop sin respuesta sigue con el reintento a 1 minuto');
    }

    public function testScheduleSelectsOnlyCredentialFailuresOfOpenOrders(): void
    {
        $this->ids = ['5', '7'];
        $this->assertSame(2, $this->repository->scheduleCredentialRetries([1, 2]));

        $this->assertSame(GenarateShipment::SHIPMENT_STATUS_FAILED, $this->where['h.status_shipment = ?']);
        $this->assertSame(DispatchError::CODE_CREDENTIALS, $this->where['h.last_error_code = ?'], 'no los que fallaron por otro motivo');
        $this->assertSame([1, 2], $this->where['o.store_id IN (?)']);
        $this->assertSame(['canceled', 'closed'], $this->where['o.state NOT IN (?)']);

        [$data, $where] = $this->updates[0];
        $this->assertEqualsWithDelta(time(), strtotime($data['next_retry_at'] . ' UTC'), 5, 'se reintentan en la próxima corrida');
        $this->assertSame(['5', '7'], $where['entity_id IN (?)']);
    }

    public function testScheduleWithoutStoresDoesNothing(): void
    {
        $this->assertSame(0, $this->repository->scheduleCredentialRetries([]));
        $this->assertSame([], $this->updates);
    }
}
