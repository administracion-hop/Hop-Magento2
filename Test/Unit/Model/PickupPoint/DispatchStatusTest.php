<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\PickupPoint\DispatchStatus;
use Magento\Framework\DataObject;
use PHPUnit\Framework\TestCase;

class DispatchStatusTest extends TestCase
{
    /**
     * @var HopEnviosRepository|\PHPUnit\Framework\MockObject\MockObject
     */
    private $repository;

    /**
     * @var DispatchStatus
     */
    private $dispatchStatus;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(HopEnviosRepository::class);
        $this->dispatchStatus = new DispatchStatus($this->repository);
    }

    public function testOrderWithoutHopRecordIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->with(10)->willReturn(null);

        $this->assertFalse($this->dispatchStatus->isDispatched(10));
    }

    public function testPendingOrderIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'pending', 'last_error_code' => null])
        );

        $this->assertFalse($this->dispatchStatus->isDispatched(10));
    }

    public function testCompletedOrderIsDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'completed', 'last_error_code' => null])
        );

        $this->assertTrue($this->dispatchStatus->isDispatched(10));
    }

    public function testRetryableFailureIsNotDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'failed', 'last_error_code' => DispatchError::CODE_ORIGIN_ZIP])
        );

        $this->assertFalse($this->dispatchStatus->isDispatched(10));
    }

    public function testPartialMultibultoIsDispatched(): void
    {
        $this->repository->method('getByOrderId')->willReturn(
            new DataObject(['status_shipment' => 'failed', 'last_error_code' => DispatchError::CODE_MULTIBULTO_PARTIAL])
        );

        $this->assertTrue($this->dispatchStatus->isDispatched(10));
    }
}
