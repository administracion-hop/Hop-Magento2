<?php

namespace Hop\Envios\Test\Unit\Model;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\CredentialState;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Hop\Envios\Model\OrderPickupPointRepository;
use Hop\Envios\Model\PointFactory;
use Hop\Envios\Model\ResourceModel\Point;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Model\ResourceModel\Token as TokenResourceModel;
use Hop\Envios\Model\ResourceModel\Token\Collection as TokenCollection;
use Hop\Envios\Model\ResourceModel\Token\CollectionFactory as TokenCollectionFactory;
use Hop\Envios\Model\TokenFactory;
use Hop\Envios\Model\Webservice;
use Magento\Framework\DataObject;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\TestCase;

/**
 * Búsqueda en Hop de un envío existente antes de despachar un pedido que una versión anterior
 * marcó como despachado sin envío en Hop (Webservice::findExistingShipment). Hop devuelve una
 * fila por bulto; la misma referencia puede tener filas de otras tiendas o envíos duplicados.
 */
class WebserviceFindExistingShipmentTest extends TestCase
{
    /** @var array cola de respuestas de Hop: [status, body] */
    private $responses = [];

    /** @var Webservice */
    private $webservice;

    /** @var string[] URLs llamadas, en orden */
    private $calls = [];

    /** @var array punto de retiro del pedido (hop_envios_order_pickup_point) */
    private $orderPoint = ['pickup_point_id' => 'punto-7', 'original_pickup_point_id' => 'punto-7'];

    protected function setUp(): void
    {
        $helper = $this->createMock(HelperHop::class);
        $helper->method('getClientId')->willReturn('client');
        $helper->method('getSellerCode')->willReturn('MENZE');
        $helper->method('getProductivo')->willReturn(false);
        $helper->method('getCustomerDocumentAttribute')->willReturn('dni');
        $helper->method('getPackageData')->willReturn(['value' => 100, 'weight' => 1]);
        $helper->method('getPackageDataForShipment')->willReturn(['value' => 50, 'weight' => 1]);

        $factory = $this->createMock(CurlFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $response = null;
            $client = $this->createMock(Curl::class);
            $send = function ($url) use (&$response) {
                $this->calls[] = $url;
                $response = array_shift($this->responses);
                if ($response instanceof \Exception) {
                    throw $response;
                }
            };
            $client->method('post')->willReturnCallback($send);
            $client->method('get')->willReturnCallback($send);
            $client->method('getStatus')->willReturnCallback(function () use (&$response) {
                return $response[0];
            });
            $client->method('getBody')->willReturnCallback(function () use (&$response) {
                return $response[1];
            });
            return $client;
        });

        $tokenCollection = $this->createMock(TokenCollection::class);
        $tokenCollection->method('setOrder')->willReturnSelf();
        $tokenCollection->method('addFieldToFilter')->willReturnSelf();
        $tokenCollection->method('setPageSize')->willReturnSelf();
        $tokenCollection->method('getFirstItem')->willReturn(new DataObject());
        $tokenCollectionFactory = $this->createMock(TokenCollectionFactory::class);
        $tokenCollectionFactory->method('create')->willReturn($tokenCollection);
        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(\Hop\Envios\Model\Token::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['save'])
                ->getMock()
        );

        $pickupPoints = $this->createMock(OrderPickupPointRepository::class);
        $pickupPoints->method('getByOrderId')->willReturnCallback(fn () => new DataObject($this->orderPoint));

        $accountResolver = $this->createMock(AccountResolver::class);
        $accountResolver->method('buildKey')->willReturn('acct:test');

        $this->webservice = new Webservice(
            $helper,
            $this->createMock(PointCollectionFactory::class),
            $this->createMock(PointFactory::class),
            $this->createMock(Point::class),
            $this->createMock(ManagerInterface::class),
            $tokenCollectionFactory,
            $tokenFactory,
            $this->createMock(TokenResourceModel::class),
            $pickupPoints,
            $factory,
            $this->createMock(HopEnviosShipmentRepository::class),
            $this->createMock(CredentialState::class),
            $accountResolver
        );
        $this->webservice->setStoreId(1);
    }

    private const LOGIN = [200, '{"token_type":"Bearer","access_token":"abc","expires_in":3600}'];

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(51);
        $order->method('getIncrementId')->willReturn('IS000000051');
        $order->method('getCustomerEmail')->willReturn('comprador@hop.test');
        $order->method('getShippingAmount')->willReturn(5198.96);
        $order->method('getBillingAddress')->willReturn(new DataObject(['telephone' => '1155554444']));
        $order->method('getShippingAddress')->willReturn(new DataObject(['country_id' => 'AR', 'postcode' => '1414']));
        return $order;
    }

    private function row(string $tracking, string $email = 'comprador@hop.test', string $point = 'punto-7'): array
    {
        return [
            'tracking_nro' => $tracking,
            'client_email' => $email,
            'pickup_point_id' => $point,
            'original_pickup_point_id' => null,
            'estimated_price' => '5198.96',
            'label_url' => 'https://etiqueta/' . $tracking . '.jpeg',
            'reference_id' => 'MENZE-IS000000051',
        ];
    }

    private function search(array $rows): array
    {
        return [200, json_encode(['data' => $rows])];
    }

    public function testFoundMultibultoIsSortedByTracking(): void
    {
        // Como en el ambiente de pruebas: una fila por bulto, el bulto 2 primero, y una fila de
        // otra tienda con la misma referencia.
        $this->responses = [self::LOGIN, $this->search([
            $this->row('399999934877216'),
            $this->row('399999934876800', 'otra.tienda@hop.test'),
            $this->row('399999934877215'),
        ])];

        $result = $this->webservice->findExistingShipment($this->order(), 2);

        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
        $this->assertSame(
            ['399999934877215', '399999934877216'],
            array_column($result['shipments'], 'tracking_nro')
        );
        $this->assertStringContainsString('search_shipments?reference=MENZE-IS000000051', end($this->calls));
    }

    public function testFoundSingleShipment(): void
    {
        $this->responses = [self::LOGIN, $this->search([$this->row('399999934877191')])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);

        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
        $this->assertCount(1, $result['shipments']);
    }

    /**
     * @return array [filas que devuelve Hop, bultos esperados, resultado]
     */
    public static function resultsProvider(): array
    {
        $row = fn ($t, $e = 'comprador@hop.test', $p = 'punto-7', $precio = '5198.96') => [
            'tracking_nro' => $t, 'client_email' => $e, 'pickup_point_id' => $p, 'estimated_price' => $precio,
        ];
        return [
            'sin envíos con la referencia' => [[], 2, Webservice::EXISTING_NONE],
            'envío de otro cliente' => [
                [$row('1', 'otro@hop.test'), $row('2', 'otro@hop.test')],
                2,
                Webservice::EXISTING_MISMATCH,
            ],
            'envío en otro punto de retiro' => [
                [$row('1', 'comprador@hop.test', 'punto-9')],
                1,
                Webservice::EXISTING_MISMATCH,
            ],
            'otro precio que el envío del pedido: corresponde igual' => [
                [$row('1', 'comprador@hop.test', 'punto-7', '100.00')],
                1,
                Webservice::EXISTING_FOUND,
            ],
            'duplicado: más bultos que envíos' => [
                [$row('1'), $row('2'), $row('3'), $row('4')],
                2,
                Webservice::EXISTING_AMBIGUOUS,
            ],
            'menos bultos que envíos' => [[$row('1')], 2, Webservice::EXISTING_AMBIGUOUS],
        ];
    }

    /**
     * @dataProvider resultsProvider
     */
    public function testResults(array $rows, int $expected, string $result): void
    {
        $this->responses = [self::LOGIN, $this->search($rows)];

        $this->assertSame($result, $this->webservice->findExistingShipment($this->order(), $expected)['result']);
    }

    public function testAnnulledBultosDoNotCount(): void
    {
        // Como MENZE2-000000001 en el ambiente de pruebas: dos bultos vigentes y dos anulados.
        $annulled = fn ($t) => array_merge($this->row($t), ['status' => 'ANU', 'status_description' => 'Anulado']);
        $this->responses = [self::LOGIN, $this->search([
            $this->row('324'), $annulled('325'), $this->row('322'), $annulled('323'),
        ])];

        $result = $this->webservice->findExistingShipment($this->order(), 2);

        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
        $this->assertSame(['322', '324'], array_column($result['shipments'], 'tracking_nro'));
    }

    public function testOnlyAnnulledBultosMeansNotInHop(): void
    {
        $row = array_merge($this->row('1'), ['status' => 'ANU']);
        $this->responses = [self::LOGIN, $this->search([$row])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_NONE, $result['result']);
    }

    public function testEmailIgnoresCaseAndSpaces(): void
    {
        $this->responses = [self::LOGIN, $this->search([$this->row('1', '  Comprador@HOP.test ')])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
    }

    public function testHopReassignedPointMatchesItsOriginalPoint(): void
    {
        $row = $this->row('1', 'comprador@hop.test', 'punto-reasignado');
        $row['original_pickup_point_id'] = 'punto-7';
        $this->responses = [self::LOGIN, $this->search([$row])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
    }

    public function testPointChosenAtCheckoutMatchesAfterChangingPoint(): void
    {
        // "Cambiar punto Hop" después del despacho viejo: Hop tiene el punto del checkout.
        $this->orderPoint = ['pickup_point_id' => 'punto-nuevo', 'original_pickup_point_id' => 'punto-7'];
        $this->responses = [self::LOGIN, $this->search([$this->row('1')])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_FOUND, $result['result']);
    }

    public function testOrderWithoutPickupPointMatchesNothing(): void
    {
        $this->orderPoint = [];
        $this->responses = [self::LOGIN, $this->search([$this->row('1')])];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_MISMATCH, $result['result']);
    }

    /**
     * @return array [HTTP, cuerpo]
     */
    public static function failedSearchProvider(): array
    {
        return [
            'error del servidor' => [503, '<html>Service Unavailable</html>'],
            'página de error con 200' => [200, '<html>error</html>'],
            'rechazo con código 4xx' => [400, '{"message":"Bad request"}'],
            'respuesta sin la lista de envíos' => [200, '{"message":"ok"}'],
        ];
    }

    /**
     * @dataProvider failedSearchProvider
     */
    public function testFailedSearch(int $status, string $body): void
    {
        $this->responses = [self::LOGIN, [$status, $body]];

        $result = $this->webservice->findExistingShipment($this->order(), 2);

        $this->assertSame(Webservice::EXISTING_ERROR, $result['result']);
        $this->assertSame([], $result['shipments']);
    }

    public function testNoResponseIsAFailedSearch(): void
    {
        $this->responses = [self::LOGIN, new \Exception('Operation timed out')];

        $result = $this->webservice->findExistingShipment($this->order(), 1);
        $this->assertSame(Webservice::EXISTING_ERROR, $result['result']);
    }

    /**
     * Lo que ya existía no cambia: cuando Hop responde "referencia ya en uso" al despachar un
     * envío, se recupera el que corresponde (mismo correo y precio).
     */
    public function testReferenceInUseStillRecoversTheMatchingShipment(): void
    {
        $this->responses = [
            self::LOGIN,
            [400, '{"message":"El elemento reference id ya está en uso.",'
                . '"errors":{"reference_id":["El elemento reference id ya está en uso."]}}'],
            $this->search([$this->row('9', 'otro@hop.test'), $this->row('399999934877191')]),
        ];

        $result = $this->webservice->createShipping($this->order());

        $this->assertIsString($result);
        $this->assertSame('399999934877191', json_decode($result, true)['tracking_nro']);
    }
}
