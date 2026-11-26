<?php

namespace Hop\Envios\Test\Unit\Observer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipment;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Hop\Envios\Model\Shipping\NativeLabelGenerator;
use Hop\Envios\Model\Webservice;
use Hop\Envios\Observer\SalesOrderShipmentSaveAfter;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection as ShipmentCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track as TrackResource;
use PHPUnit\Framework\TestCase;

/**
 * Despacho de un pedido cuyos envíos de Magento ya se mandaron todos a Hop: no se llama a Hop
 * y se registra lo que ya pasó. Encontrado en la prueba del 26/11/2026 (IS000000051): un
 * multibulto con un bulto aceptado y otro rechazado quedó "No despachado", y "Despachar a Hop"
 * daba "error al enviar a Hop" sin motivo.
 */
class SalesOrderShipmentSaveAfterProcessedTest extends TestCase
{
    /** @var HopEnviosRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $hopEnviosRepository;

    /** @var Webservice|\PHPUnit\Framework\MockObject\MockObject */
    private $webservice;

    /** @var HopEnviosShipment[] */
    private $records = [];

    /** @var HopEnvios */
    private $hopEnvio;

    /** @var SalesOrderShipmentSaveAfter */
    private $observer;

    /** @var Shipment[] */
    private $shipments;

    protected function setUp(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isActive')->willReturn(true);

        $this->hopEnvio = $this->model(HopEnvios::class, ['entity_id' => 10, 'order_id' => 51]);
        $this->hopEnviosRepository = $this->createMock(HopEnviosRepository::class);
        $this->hopEnviosRepository->method('getByOrderId')->willReturnCallback(fn () => $this->hopEnvio);

        $bultos = $this->createMock(HopEnviosShipmentRepository::class);
        $bultos->method('getByHopEnvioId')->willReturnCallback(fn () => $this->records);

        $this->shipments = [$this->shipment(101), $this->shipment(102)];
        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('setOrderFilter')->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(fn () => $this->shipments);
        $collectionFactory = $this->createMock(ShipmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        // Ninguno de estos casos puede llamar a Hop.
        $this->webservice = $this->createMock(Webservice::class);
        $this->webservice->expects($this->never())->method('createShipping');
        $this->webservice->expects($this->never())->method('createShippingMultibulto');

        $this->observer = new SalesOrderShipmentSaveAfter(
            $helper,
            $this->hopEnviosRepository,
            $bultos,
            $this->webservice,
            $collectionFactory,
            $this->createMock(TrackFactory::class),
            $this->createMock(TrackResource::class),
            $this->createMock(NativeLabelGenerator::class)
        );
    }

    /**
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    private function model(string $class, array $data)
    {
        $model = $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods(['save'])->getMock();
        $model->setData($data);
        return $model;
    }

    private function shipment(int $id): Shipment
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(51);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturn('hop_hop');
        $order->method('canShip')->willReturn(false);

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getId')->willReturn($id);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getAllTracks')->willReturn([]);
        return $shipment;
    }

    private function bulto(int $shipmentId, string $status, ?string $tracking): HopEnviosShipment
    {
        return $this->model(HopEnviosShipment::class, [
            'hop_envio_id' => 10,
            'shipment_id' => $shipmentId,
            'status' => $status,
            'tracking_nro' => $tracking,
        ]);
    }

    /**
     * @return array [estado del despacho]
     */
    public static function stuckStatusProvider(): array
    {
        return [
            'quedó pendiente' => ['pending'],
            'quedó en proceso' => ['processing'],
        ];
    }

    /**
     * @dataProvider stuckStatusProvider
     */
    public function testRejectedBultoIsRecordedAsPartial(string $status): void
    {
        $this->hopEnvio->setStatusShipment($status);
        $this->records = [$this->bulto(101, 'completed', 'HOP123'), $this->bulto(102, 'failed', null)];

        $this->hopEnviosRepository->expects($this->once())->method('markFailed')
            ->with(
                $this->hopEnvio,
                $this->callback(fn ($error) => str_contains((string)$error, 'Ya creado en Hop con bultos rechazados: 1 de 2')),
                null,
                DispatchError::CODE_MULTIBULTO_PARTIAL
            );
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');

        $this->assertFalse($this->observer->dispatch($this->shipments[1]));
    }

    public function testAllBultosAcceptedIsRecordedAsDispatched(): void
    {
        $this->hopEnvio->setStatusShipment('pending');
        $this->records = [$this->bulto(101, 'completed', 'HOP123'), $this->bulto(102, 'completed', 'HOP124')];

        $this->hopEnviosRepository->expects($this->once())->method('markCompleted')->with($this->hopEnvio);
        $this->hopEnviosRepository->expects($this->never())->method('markFailed');

        $this->assertTrue($this->observer->dispatch($this->shipments[1]));
    }

    /**
     * @return array [estado, código]
     */
    public static function alreadyRecordedProvider(): array
    {
        return [
            'ya parcial' => ['failed', DispatchError::CODE_MULTIBULTO_PARTIAL],
            'ya despachado' => ['completed', null],
        ];
    }

    /**
     * Un pedido ya registrado no cambia: es lo que pasa en cada guardado posterior de sus envíos
     * (por ejemplo, cuando Magento guarda la etiqueta en el envío).
     *
     * @dataProvider alreadyRecordedProvider
     */
    public function testAlreadyRecordedIsNotTouched(string $status, ?string $code): void
    {
        $this->hopEnvio->setStatusShipment($status);
        $this->hopEnvio->setLastErrorCode($code);
        $this->records = [$this->bulto(101, 'completed', 'HOP123'), $this->bulto(102, 'failed', null)];

        $this->hopEnviosRepository->expects($this->never())->method('markFailed');
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');

        $this->assertNull($this->observer->dispatch($this->shipments[1]));
    }

    public function testBultosWithoutTrackingOrRejectionAreLeftForManualReview(): void
    {
        $this->hopEnvio->setStatusShipment('pending');
        $this->records = [$this->bulto(101, 'completed', 'HOP123'), $this->bulto(102, 'unsupported', null)];

        $this->hopEnviosRepository->expects($this->never())->method('markFailed');
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');

        $this->assertNull($this->observer->dispatch($this->shipments[1]));
    }
}
