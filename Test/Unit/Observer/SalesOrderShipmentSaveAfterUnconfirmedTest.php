<?php

namespace Hop\Envios\Test\Unit\Observer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\DispatchError;
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
 * Despacho de un pedido que una versión anterior marcó como despachado sin envío en Hop (código
 * CODE_UNCONFIRMED_DISPATCH, lo asigna el parche ReopenDispatchWithoutHopRecord): antes de crear
 * el envío se busca en Hop, y solo se crea si Hop no tiene ninguno con la referencia del pedido.
 */
class SalesOrderShipmentSaveAfterUnconfirmedTest extends TestCase
{
    /** @var HopEnviosRepository|\PHPUnit\Framework\MockObject\MockObject */
    private $hopEnviosRepository;

    /** @var Webservice|\PHPUnit\Framework\MockObject\MockObject */
    private $webservice;

    /** @var HopEnvios */
    private $hopEnvio;

    /** @var SalesOrderShipmentSaveAfter */
    private $observer;

    /** @var Shipment[] */
    private $shipments;

    /** @var array [id de envío, índice, número de seguimiento, etiqueta] de cada bulto guardado */
    private $savedBultos = [];

    /** @var array [id de envío, número] de cada seguimiento agregado */
    private $addedTracks = [];

    /** @var int[] envíos a los que se les generó la etiqueta */
    private $labels = [];

    /** @var string[] orden de los pasos: "bulto <id>", "seguimiento <id>", "etiqueta <id>" */
    private $steps = [];

    /** @var array medidas de cada envío de Magento por id (getPackageDataForShipment) */
    private $packages = [];

    /** @var int|null id de envío cuyo bulto falla al guardarse */
    private $failingBulto = null;

    /** @var array [id de despacho, ids de envío] de cada borrado de bultos */
    private $deleted = [];

    protected function setUp(): void
    {
        $helper = $this->createMock(Data::class);
        $helper->method('isActive')->willReturn(true);
        $helper->method('getPackageDataForShipment')->willReturnCallback(
            fn ($shipment) => $this->packages[(int)$shipment->getId()] ?? null
        );

        $this->hopEnvio = $this->model(HopEnvios::class, [
            'entity_id' => 10,
            'order_id' => 51,
            'status_shipment' => 'failed',
            'last_error_code' => DispatchError::CODE_UNCONFIRMED_DISPATCH,
        ]);
        $this->hopEnviosRepository = $this->createMock(HopEnviosRepository::class);
        $this->hopEnviosRepository->method('getByOrderId')->willReturnCallback(fn () => $this->hopEnvio);
        $this->hopEnviosRepository->method('markCompleted')->willReturnCallback(function () {
            $this->steps[] = 'completado';
        });

        $bultos = $this->createMock(HopEnviosShipmentRepository::class);
        $bultos->method('getByHopEnvioId')->willReturn([]);
        $bultos->method('saveForShipment')->willReturnCallback(
            function ($hopEnvioId, $shipmentId, $index, $shippingId, $tracking, $label) {
                if ($shipmentId === $this->failingBulto) {
                    throw new \Exception('no se pudo guardar el bulto');
                }
                $this->savedBultos[] = [$shipmentId, $index, $tracking, $label];
                $this->steps[] = 'bulto ' . $shipmentId;
                return $this->model(\Hop\Envios\Model\HopEnviosShipment::class, []);
            }
        );

        $bultos->method('deleteForShipments')->willReturnCallback(function ($hopEnvioId, $ids) {
            $this->deleted[] = [$hopEnvioId, $ids];
        });

        // Desordenados a propósito: los bultos se asignan por id de envío, como en el despacho.
        $this->shipments = [$this->shipment(102), $this->shipment(101)];
        $collection = $this->createMock(ShipmentCollection::class);
        $collection->method('setOrderFilter')->willReturnSelf();
        $collection->method('getItems')->willReturnCallback(fn () => $this->shipments);
        $collectionFactory = $this->createMock(ShipmentCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $this->webservice = $this->createMock(Webservice::class);

        $trackFactory = $this->getMockBuilder(TrackFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $trackFactory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(\Magento\Sales\Model\Order\Shipment\Track::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['save'])
                ->getMock()
        );
        $trackResource = $this->createMock(TrackResource::class);
        $trackResource->method('save')->willReturnCallback(function ($track) use ($trackResource) {
            $this->addedTracks[] = [(int)$track->getParentId(), $track->getTrackNumber()];
            $this->steps[] = 'seguimiento ' . (int)$track->getParentId();
            return $trackResource;
        });
        $labels = $this->createMock(NativeLabelGenerator::class);
        $labels->method('generate')->willReturnCallback(function ($shipment) {
            $this->labels[] = (int)$shipment->getId();
            $this->steps[] = 'etiqueta ' . (int)$shipment->getId();
            return true;
        });

        $this->observer = new SalesOrderShipmentSaveAfter(
            $helper,
            $this->hopEnviosRepository,
            $bultos,
            $this->webservice,
            $collectionFactory,
            $trackFactory,
            $trackResource,
            $labels
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

    private function search(string $result, array $rows = []): void
    {
        $this->webservice->expects($this->once())->method('findExistingShipment')
            ->with($this->anything(), count($this->shipments))
            ->willReturn(['result' => $result, 'shipments' => $rows]);
    }

    private function row(string $tracking): array
    {
        return [
            'tracking_nro' => $tracking,
            'label_url' => 'https://etiqueta/' . $tracking . '.jpeg',
            'client_email' => 'comprador@hop.test',
            'estimated_price' => '5198.96',
        ];
    }

    public function testFoundInHopIsRecoveredWithoutCreatingAnotherShipment(): void
    {
        $this->search(Webservice::EXISTING_FOUND, [$this->row('HOP1'), $this->row('HOP2')]);
        $this->webservice->expects($this->never())->method('createShippingMultibulto');
        $this->webservice->expects($this->never())->method('createShipping');
        $this->hopEnviosRepository->expects($this->once())->method('markCompleted')->with($this->hopEnvio, null);
        $this->hopEnviosRepository->expects($this->never())->method('markFailed');

        $this->assertTrue($this->observer->dispatch($this->shipments[0], true));
        $this->assertSame(
            [[101, 0, 'HOP1', 'https://etiqueta/HOP1.jpeg'], [102, 1, 'HOP2', 'https://etiqueta/HOP2.jpeg']],
            $this->savedBultos,
            'un bulto por envío de Magento, en orden, con su seguimiento y su etiqueta'
        );
        $this->assertSame([[101, 'HOP1'], [102, 'HOP2']], $this->addedTracks);
        $this->assertSame([101, 102], $this->labels);
        // Generar la etiqueta guarda el envío y vuelve a disparar el observer: para entonces
        // todos los bultos tienen que estar guardados (encontrado en la prueba del 27/11/2026).
        $this->assertSame(
            [
                'bulto 101', 'bulto 102', 'completado',
                'seguimiento 101', 'etiqueta 101', 'seguimiento 102', 'etiqueta 102',
            ],
            $this->steps
        );
    }

    public function testSingleShipmentFoundKeepsHopResponse(): void
    {
        $this->shipments = [$this->shipment(101)];
        $this->search(Webservice::EXISTING_FOUND, [$this->row('HOP1')]);
        $this->webservice->expects($this->never())->method('createShipping');
        $this->hopEnviosRepository->expects($this->once())->method('markCompleted')
            ->with($this->hopEnvio, json_encode($this->row('HOP1')));

        $this->assertTrue($this->observer->dispatch($this->shipments[0], true));
        $this->assertSame([[101, 0, 'HOP1', 'https://etiqueta/HOP1.jpeg']], $this->savedBultos);
    }

    /**
     * @return array [resultado de la búsqueda, filas, motivo esperado, código esperado]
     */
    public static function notCreatedProvider(): array
    {
        return [
            'existe pero no corresponde' => [
                Webservice::EXISTING_MISMATCH,
                [],
                DispatchError::existingMismatchMessage(),
                DispatchError::CODE_REFERENCE_IN_USE,
            ],
            'más bultos en Hop que envíos (duplicado)' => [
                Webservice::EXISTING_AMBIGUOUS,
                [['tracking_nro' => '1'], ['tracking_nro' => '2'], ['tracking_nro' => '3']],
                DispatchError::existingAmbiguousMessage(3, 2),
                DispatchError::CODE_REFERENCE_IN_USE,
            ],
            'la búsqueda falla' => [
                Webservice::EXISTING_ERROR,
                [],
                DispatchError::existingSearchFailedMessage(),
                DispatchError::CODE_UNCONFIRMED_DISPATCH,
            ],
        ];
    }

    /**
     * @dataProvider notCreatedProvider
     */
    public function testNothingIsCreatedWhenItCannotBeConfirmed(
        string $result,
        array $rows,
        string $message,
        string $code
    ): void {
        $this->search($result, $rows);
        $this->webservice->expects($this->never())->method('createShippingMultibulto');
        $this->webservice->expects($this->never())->method('createShipping');
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');
        $this->hopEnviosRepository->expects($this->once())->method('markFailed')
            ->with($this->hopEnvio, $message, null, $code);

        $this->assertFalse($this->observer->dispatch($this->shipments[0], true));
        $this->assertSame([], $this->savedBultos);
        $this->assertSame([], $this->addedTracks);
    }

    public function testNotInHopIsDispatchedNormally(): void
    {
        $this->search(Webservice::EXISTING_NONE);
        $this->webservice->expects($this->once())->method('createShippingMultibulto')->willReturn(true);
        $this->hopEnviosRepository->expects($this->once())->method('markCompleted')->with($this->hopEnvio);

        $this->assertTrue($this->observer->dispatch($this->shipments[0], true));
    }

    public function testSearchFailedByCredentialsSaysSo(): void
    {
        $this->search(Webservice::EXISTING_ERROR);
        $this->webservice->method('wasRejectedByCredentials')->willReturn(true);
        $this->webservice->expects($this->never())->method('createShippingMultibulto');
        $this->hopEnviosRepository->expects($this->once())->method('markFailed')
            ->with(
                $this->hopEnvio,
                DispatchError::existingSearchCredentialsMessage(),
                null,
                DispatchError::CODE_UNCONFIRMED_DISPATCH
            );

        $this->assertFalse($this->observer->dispatch($this->shipments[0], true));
    }

    /**
     * @return array [HTTP del despacho, código esperado]
     */
    public static function failedAfterNotFoundProvider(): array
    {
        return [
            'sin respuesta: puede haberlo creado, sigue sin confirmar' => [0, DispatchError::CODE_UNCONFIRMED_DISPATCH],
            'error del servidor: sigue sin confirmar' => [502, DispatchError::CODE_UNCONFIRMED_DISPATCH],
            'respuesta 2xx inesperada: sigue sin confirmar' => [200, DispatchError::CODE_UNCONFIRMED_DISPATCH],
            'Hop lo rechazó: no lo creó, código de siempre' => [400, null],
        ];
    }

    /**
     * La búsqueda no encontró nada y el despacho falla: si quedó la duda de si Hop lo creó, el
     * próximo intento tiene que volver a buscar (y no se reintenta solo).
     *
     * @dataProvider failedAfterNotFoundProvider
     */
    public function testFailedDispatchAfterNotFoundKeepsSearchingWhenUncertain(int $status, ?string $code): void
    {
        $this->search(Webservice::EXISTING_NONE);
        $this->webservice->method('createShippingMultibulto')->willReturn(false);
        $this->webservice->method('getLastStatus')->willReturn($status);
        $this->webservice->method('getLastMultibultoError')->willReturn('falló');
        $this->hopEnviosRepository->expects($this->once())->method('markFailed')
            ->with($this->hopEnvio, 'falló', $status, $code);

        $this->assertFalse($this->observer->dispatch($this->shipments[0], true));
    }

    public function testAnyOtherShipmentSaveDoesNotDispatchIt(): void
    {
        // Un guardado del envío (un comentario, una etiqueta) dispara el observer sin que nadie
        // haya pedido despachar: un pedido sin confirmar no se toca.
        $this->webservice->expects($this->never())->method('findExistingShipment');
        $this->webservice->expects($this->never())->method('createShippingMultibulto');
        $this->hopEnviosRepository->expects($this->never())->method('markFailed');
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');

        $this->assertNull($this->observer->dispatch($this->shipments[0]));
    }

    public function testBultosAreMatchedByTheirDimensions(): void
    {
        // Hop devolvió el bulto grande con el número de seguimiento más bajo: las medidas mandan.
        $this->packages = [
            101 => ['length' => 30, 'height' => 3, 'width' => 20],
            102 => ['length' => 60, 'height' => 6, 'width' => 40],
        ];
        $big = $this->row('HOP1')
            + ['package_length' => '60.00', 'package_height' => '6.00', 'package_width' => '40.00'];
        $small = $this->row('HOP2')
            + ['package_length' => '30.00', 'package_height' => '3.00', 'package_width' => '20.00'];
        $this->search(Webservice::EXISTING_FOUND, [$big, $small]);

        $this->assertTrue($this->observer->dispatch($this->shipments[0], true));
        $this->assertSame([101, 102], array_column($this->savedBultos, 0));
        $this->assertSame(['HOP2', 'HOP1'], array_column($this->savedBultos, 2));
    }

    public function testRecoveryCutHalfwayLeavesNoBultos(): void
    {
        $this->failingBulto = 102;
        $this->search(Webservice::EXISTING_FOUND, [$this->row('HOP1'), $this->row('HOP2')]);
        $this->hopEnviosRepository->expects($this->never())->method('markCompleted');
        $this->hopEnviosRepository->expects($this->once())->method('markFailed')
            ->with($this->hopEnvio, 'no se pudo guardar el bulto', null, DispatchError::CODE_UNCONFIRMED_DISPATCH);

        $this->assertFalse($this->observer->dispatch($this->shipments[0], true));
        $this->assertSame([[10, [101]]], $this->deleted, 'se borra el bulto que alcanzó a guardarse');
    }

    public function testOtherFailuresDoNotSearchFirst(): void
    {
        $this->hopEnvio->setLastErrorCode('client_id');
        $this->webservice->expects($this->never())->method('findExistingShipment');
        $this->webservice->expects($this->once())->method('createShippingMultibulto')->willReturn(true);

        $this->assertTrue($this->observer->dispatch($this->shipments[0], true));
    }
}
