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
 * Despacho (un bulto y multibulto) cuando Hop responde un 4xx: una página de error de su
 * servidor (no JSON) se reintenta por tiempo como un 5xx; un rechazo con el formato de Hop no.
 * Encontrado en la prueba del 26/11/2026: un "502 Bad Gateway" con código 400 no se reintentaba.
 */
class WebserviceServerErrorResponseTest extends TestCase
{
    private const BAD_GATEWAY = '<html><head><title>502 Bad Gateway</title></head><body><center><h1>502 Bad Gateway</h1></center></body></html>';

    /** @var array cola de respuestas de Hop: [status, body] */
    private $responses = [];

    /** @var Webservice */
    private $webservice;

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
            $send = function () use (&$response) {
                $response = array_shift($this->responses);
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
            fn () => $this->getMockBuilder(\Hop\Envios\Model\Token::class)->disableOriginalConstructor()->onlyMethods(['save'])->getMock()
        );

        $pickupPoints = $this->createMock(OrderPickupPointRepository::class);
        $pickupPoints->method('getByOrderId')->willReturn(new DataObject(['pickup_point_id' => 7]));

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

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(51);
        $order->method('getIncrementId')->willReturn('IS000000051');
        $order->method('getBillingAddress')->willReturn(new DataObject(['telephone' => '1155554444']));
        $order->method('getShippingAddress')->willReturn(new DataObject(['country_id' => 'AR', 'postcode' => '1414']));
        return $order;
    }

    /**
     * Minutos hasta el reintento que agendaría markFailed() con lo que deja el despacho.
     */
    private function retryDelay(string $error): ?int
    {
        return DispatchError::retryDelayMinutes(1, $this->webservice->getLastStatus(), DispatchError::classify($error));
    }

    /**
     * @return array [HTTP, cuerpo, ¿se reintenta solo?, status que queda para markFailed]
     */
    public static function responsesProvider(): array
    {
        return [
            'página 502 Bad Gateway con código 400' => [400, self::BAD_GATEWAY, true, 0],
            'página de error con código 403' => [403, '<html>Forbidden</html>', true, 0],
            'cuerpo vacío con código 400' => [400, '', true, 0],
            'rechazo de Hop con su formato (falta el documento)' => [400, '{"message":"Error de validación","errors":{"client.id_number":["El campo client.id number es obligatorio."]}}', false, 400],
            'rechazo de Hop 422 con su formato' => [422, '{"package.weight":["El campo package.weight es obligatorio."]}', false, 422],
            'demasiadas consultas con página de error' => [429, '<html>Too Many Requests</html>', false, 429],
            'error 502 del servidor, como hoy' => [502, self::BAD_GATEWAY, true, 502],
        ];
    }

    /**
     * @dataProvider responsesProvider
     */
    public function testSinglePackage(int $status, string $body, bool $retries, int $statusForMarkFailed): void
    {
        $this->responses = [[200, '{"token_type":"Bearer","access_token":"abc","expires_in":3600}'], [$status, $body]];

        $result = $this->webservice->createShipping($this->order());

        $this->assertIsArray($result);
        $this->assertSame($statusForMarkFailed, $this->webservice->getLastStatus());
        $this->assertSame($retries ? 1 : null, $this->retryDelay((string)$result['error']));
        $this->assertStringNotContainsString('<html', (string)$result['error'], 'la página no va al mensaje que ve el admin');
        if ($statusForMarkFailed === 0) {
            $this->assertStringContainsString('error de su servidor (HTTP ' . $status . ')', (string)$result['error']);
        }
    }

    /**
     * @dataProvider responsesProvider
     */
    public function testMultibulto(int $status, string $body, bool $retries, int $statusForMarkFailed): void
    {
        $this->responses = [[200, '{"token_type":"Bearer","access_token":"abc","expires_in":3600}'], [$status, $body]];
        $shipments = [$this->createMock(Shipment::class), $this->createMock(Shipment::class)];

        $ok = $this->webservice->createShippingMultibulto($this->order(), $shipments, 10);

        $this->assertFalse($ok);
        $error = (string)$this->webservice->getLastMultibultoError();
        $this->assertSame($statusForMarkFailed, $this->webservice->getLastStatus());
        $this->assertSame($retries ? 1 : null, $this->retryDelay($error));
        if ($statusForMarkFailed === 0) {
            $this->assertStringContainsString('error de su servidor (HTTP ' . $status . ')', $error);
            $this->assertStringNotContainsString('<html', $error);
        }
    }
}
