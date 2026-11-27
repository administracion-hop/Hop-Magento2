<?php

namespace Hop\Envios\Test\Unit\Observer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosRepository;
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
 * Multibulto despachado al guardar el último envío: Magento dispara el evento antes de guardar
 * los artículos de ese envío, así que la copia que lee la colección no los tiene. El despacho
 * tiene que mandar a Hop el envío del evento (con sus artículos en memoria); con la copia, el
 * bulto viajaba vacío y Hop rechazaba el multibulto entero (encontrado en la prueba del
 * 27/11/2026, y en el registro del 12/11/2026 de la tienda local).
 */
class SalesOrderShipmentSaveAfterLastBultoTest extends TestCase
{
    /** @var Webservice|\PHPUnit\Framework\MockObject\MockObject */
    private $webservice;

    /** @var SalesOrderShipmentSaveAfter */
    private $observer;

    /** @var Shipment[] lo que devuelve la colección */
    private $loaded;

    protected function setUp(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isActive')->willReturn(true);

        $hopEnvio = $this->getMockBuilder(HopEnvios::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $hopEnvio->setData(['entity_id' => 10, 'order_id' => 51, 'status_shipment' => 'pending']);
        $hopEnviosRepository = $this->createMock(HopEnviosRepository::class);
        $hopEnviosRepository->method('getByOrderId')->willReturn($hopEnvio);

        $bultos = $this->createMock(HopEnviosShipmentRepository::class);
        $bultos->method('getByHopEnvioId')->willReturn([]);

        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('setOrderFilter')->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(fn () => $this->loaded);
        $collectionFactory = $this->createMock(ShipmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->webservice = $this->createMock(Webservice::class);

        $this->observer = new SalesOrderShipmentSaveAfter(
            $helper,
            $hopEnviosRepository,
            $bultos,
            $this->webservice,
            $collectionFactory,
            $this->getMockBuilder(TrackFactory::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['create'])
                ->getMock(),
            $this->createMock(TrackResource::class),
            $this->createMock(NativeLabelGenerator::class)
        );
    }

    private function shipment(int $id, string $copia): Shipment
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
        $shipment->method('getIncrementId')->willReturn($copia);
        return $shipment;
    }

    public function testBultosGoToHopOrderedByShipmentId(): void
    {
        $second = $this->shipment(102, 'segundo envío');
        $first = $this->shipment(101, 'primer envío');
        $this->loaded = [$second, $first];

        $this->webservice->expects($this->once())->method('createShippingMultibulto')
            ->with(
                $this->anything(),
                $this->callback(fn (array $shipments) => $shipments === [$first, $second]),
                10
            )
            ->willReturn(true);

        $this->observer->dispatch($second);
    }

    public function testLastBultoIsTheShipmentOfTheEvent(): void
    {
        $first = $this->shipment(101, 'primer envío, ya guardado');
        $lastFromCollection = $this->shipment(102, 'copia leída de la base, sin artículos');
        $lastFromEvent = $this->shipment(102, 'envío del evento, con sus artículos');
        $this->loaded = [$first, $lastFromCollection];

        $this->webservice->expects($this->once())->method('createShippingMultibulto')
            ->with(
                $this->anything(),
                $this->callback(function (array $shipments) use ($first, $lastFromEvent) {
                    return count($shipments) === 2 && $shipments[0] === $first && $shipments[1] === $lastFromEvent;
                }),
                10
            )
            ->willReturn(true);

        $this->observer->dispatch($lastFromEvent);
    }
}
