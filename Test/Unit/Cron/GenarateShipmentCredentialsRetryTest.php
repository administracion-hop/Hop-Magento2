<?php

namespace Hop\Envios\Test\Unit\Cron;

use Hop\Envios\Cron\GenarateShipment;
use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\ResourceModel\HopEnvios\Collection as HopEnviosCollection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Shipping\Model\ShipmentNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reintento automático de los despachos que fallaron por credenciales.
 */
class GenarateShipmentCredentialsRetryTest extends TestCase
{
    /** @var HopEnvios */
    private $hopEnvio;

    /** @var Order|\PHPUnit\Framework\MockObject\MockObject */
    private $order;

    /** @var string estado del despacho después del reintento */
    private $statusAfterRetry = GenarateShipment::SHIPMENT_STATUS_COMPLETED;

    /** @var int */
    private $dispatches = 0;

    /** @var string[] */
    private $comments = [];

    /** @var GenarateShipment */
    private $cron;

    /** @var string fecha UTC del pedido */
    private $createdAt;

    protected function setUp(): void
    {
        $this->createdAt = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
        $this->hopEnvio = $this->getMockBuilder(HopEnvios::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $this->hopEnvio->setData([
            'order_id' => 10,
            'status_shipment' => GenarateShipment::SHIPMENT_STATUS_FAILED,
            'last_error_code' => DispatchError::CODE_CREDENTIALS,
            'last_error' => 'Hop rechazó las credenciales de inicio de sesión.',
        ]);

        $this->order = $this->createMock(Order::class);
        $this->order->method('load')->willReturnSelf();
        $this->order->method('getId')->willReturn(10);
        $this->order->method('canShip')->willReturn(false);
        $this->order->method('getCreatedAt')->willReturnCallback(fn () => $this->createdAt);
        $this->order->method('addCommentToStatusHistory')->willReturnCallback(function ($comment) {
            $this->comments[] = (string)$comment;
            return $this->createMock(OrderStatusHistoryInterface::class);
        });
        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($this->order);

        $empty = $this->createMock(HopEnviosCollection::class);
        $empty->method('getIterator')->willReturn(new \ArrayIterator([]));
        $empty->method('count')->willReturn(0);
        $failed = $this->createMock(HopEnviosCollection::class);
        $failed->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator([$this->hopEnvio]));

        $repository = $this->createMock(HopEnviosRepository::class);
        $repository->method('getCollectionByStatusShipment')->willReturn($empty);
        $repository->method('getRetryableFailed')->willReturn($failed);
        $repository->method('getByOrderId')->willReturnCallback(function () {
            $after = clone $this->hopEnvio;
            if ($this->dispatches === 0) {
                return $after; // no se llegó a intentar: nada cambió
            }
            $after->setStatusShipment($this->statusAfterRetry);
            if ($this->statusAfterRetry === GenarateShipment::SHIPMENT_STATUS_FAILED) {
                // markFailed vuelve a dejar el motivo.
                $after->setLastError('Hop rechazó las credenciales de inicio de sesión.');
                $after->setLastErrorCode(DispatchError::CODE_CREDENTIALS);
            } else {
                $after->setLastError(null);
            }
            return $after;
        });

        // El pedido ya tiene envío de Magento: el reintento va por retryDispatch().
        $shipments = $this->createMock(ShipmentCollection::class);
        $shipments->method('addFieldToFilter')->willReturnSelf();
        $shipments->method('getSize')->willReturn(1);
        $shipmentCollectionFactory = $this->createMock(ShipmentCollectionFactory::class);
        $shipmentCollectionFactory->method('create')->willReturn($shipments);

        $shippingMethod = $this->createMock(ShippingMethod::class);
        $shippingMethod->method('retryDispatch')->willReturnCallback(function () {
            $this->dispatches++;
            return true;
        });

        $this->cron = new GenarateShipment(
            $orderFactory,
            $this->createMock(ShipmentFactory::class),
            $this->createMock(TransactionFactory::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ShipmentNotifier::class),
            $repository,
            $shipmentCollectionFactory,
            $shippingMethod,
            $this->createMock(OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(\Hop\Envios\Model\Health\CredentialRetryScheduler::class)
        );
    }

    public function testRetriedOrderGetsCommentWithResult(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->cron->execute();

        $this->assertSame(1, $this->dispatches);
        $this->assertCount(1, $this->comments);
        $this->assertStringContainsString('Reintentado automáticamente al recuperarse las credenciales de Hop', $this->comments[0]);
        $this->assertStringContainsString('despachado a Hop', $this->comments[0]);
    }

    public function testFailedRetryCommentSaysSo(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->statusAfterRetry = GenarateShipment::SHIPMENT_STATUS_FAILED;
        $this->cron->execute();

        $this->assertStringContainsString('falló de nuevo', $this->comments[0]);
    }

    /**
     * @return array
     */
    public static function closedStates(): array
    {
        return [
            'cancelado' => [Order::STATE_CANCELED],
            'cerrado' => [Order::STATE_CLOSED],
            'completo' => [Order::STATE_COMPLETE],
            'retenido' => [Order::STATE_HOLDED],
        ];
    }

    /**
     * @dataProvider closedStates
     */
    public function testCanceledOrClosedOrdersAreNotRetried($state): void
    {
        $this->order->method('getState')->willReturn($state);
        $this->cron->execute();

        $this->assertSame(0, $this->dispatches);
        $this->assertSame([], $this->comments);
    }

    public function testOrderOutsideCriteriaKeepsWaiting(): void
    {
        // Retenido justo después de reprogramarse: no se despacha, pero conserva el motivo para
        // reprogramarse cuando se libere.
        $this->order->method('getState')->willReturn(Order::STATE_HOLDED);
        $this->cron->execute();

        $this->assertSame(0, $this->dispatches);
        $this->assertSame(\Hop\Envios\Model\DispatchError::CODE_CREDENTIALS, $this->hopEnvio->getLastErrorCode());
        $this->assertSame([], $this->comments);
    }

    public function testRetryClearsReasonRightBeforeDispatching(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->cron->execute();

        $this->assertSame(1, $this->dispatches);
        $this->assertNull($this->hopEnvio->getLastErrorCode(), 'el motivo nuevo lo deja markFailed si vuelve a fallar');
    }

    public function testOldOrdersAreNotRetried(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->createdAt = gmdate('Y-m-d H:i:s', time() - 45 * 86400);
        $this->cron->execute();

        $this->assertSame(0, $this->dispatches, 'un pedido de hace más de 30 días no se reintenta solo');
        $this->assertSame([], $this->comments);
    }

    public function testOtherFailuresGetNoComment(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);
        $this->hopEnvio->setLastErrorCode(null);
        $this->cron->execute();

        $this->assertSame(1, $this->dispatches, 'el reintento por errores del servidor sigue igual');
        $this->assertSame([], $this->comments);
    }
}
