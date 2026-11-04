<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\PickupPoint\DispatchStatus;
use Magento\Framework\DataObject;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use PHPUnit\Framework\TestCase;

class DispatchStatusTest extends TestCase
{
    /**
     * @var HopEnviosRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private $repository;

    /**
     * @var int Number of Magento shipments the order has
     */
    private $shipmentCount = 0;

    /**
     * @var DispatchStatus
     */
    private $dispatchStatus;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(HopEnviosRepository::class);
        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('addFieldToFilter')->with('order_id', 10)->willReturnSelf();
        $collection->method('getSize')->willReturnCallback(fn () => $this->shipmentCount);
        $factory = $this->createMock(ShipmentCollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $this->dispatchStatus = new DispatchStatus($this->repository, $factory);
    }

    /**
     * @return array [status_shipment, last_error_code, shipments, expected hasDispatchStarted]
     */
    public static function dispatchStartedCases(): array
    {
        return [
            'sin registro ni envío' => [null, null, 0, false],
            'pendiente' => ['pending', null, 0, false],
            'el cron está despachando' => ['processing', null, 0, true],
            'despachado' => ['completed', null, 0, true],
            'falla reintentable' => ['failed', DispatchError::CODE_ORIGIN_ZIP, 0, false],
            'falla no reintentable' => ['failed', DispatchError::CODE_MULTIBULTO_PARTIAL, 0, true],
            'pendiente con envío de Magento' => ['pending', null, 1, true],
            'sin registro con envío de Magento' => [null, null, 1, true],
        ];
    }

    /**
     * @dataProvider dispatchStartedCases
     */
    public function testHasDispatchStarted(?string $status, ?string $errorCode, int $shipments, bool $expected): void
    {
        $this->repository->method('getByOrderId')->with(10)->willReturn(
            $status === null ? null : new DataObject(['status_shipment' => $status, 'last_error_code' => $errorCode])
        );
        $this->shipmentCount = $shipments;

        $this->assertSame($expected, $this->dispatchStatus->hasDispatchStarted(10));
    }

    public function testAdminCutOffIgnoresProcessingAndShipments(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'processing', 'last_error_code' => null])
        );
        $this->shipmentCount = 1;

        $this->assertFalse($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }

    public function testOrderWithoutHopRecordIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->with(10)->willReturn(null);

        $this->assertFalse($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }

    public function testPendingOrderIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'pending', 'last_error_code' => null])
        );

        $this->assertFalse($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }

    public function testCompletedOrderIsDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'completed', 'last_error_code' => null])
        );

        $this->assertTrue($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }

    public function testRetryableFailureIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'failed', 'last_error_code' => DispatchError::CODE_ORIGIN_ZIP])
        );

        $this->assertFalse($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }

    public function testPartialMultibultoIsDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'failed', 'last_error_code' => DispatchError::CODE_MULTIBULTO_PARTIAL])
        );

        $this->assertTrue($this->dispatchStatus->isRecordDispatched($this->repository->getByOrderId(10)));
    }
}
