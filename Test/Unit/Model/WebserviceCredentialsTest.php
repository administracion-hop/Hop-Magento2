<?php

namespace Hop\Envios\Test\Unit\Model;

use Hop\Envios\Helper\Data as HelperHop;
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
use PHPUnit\Framework\TestCase;

/**
 * Clasificación del inicio de sesión (rechazo contra falta de respuesta), corte en el primer
 * rechazo y registro del estado de las credenciales.
 */
class WebserviceCredentialsTest extends TestCase
{
    /** @var array cola de respuestas de Hop: [status, body] o \Exception */
    private $responses = [];

    /** @var string[] URLs llamadas, en orden */
    private $calls = [];

    /** @var array registros de CredentialState::record */
    private $recorded = [];

    /** @var DataObject|null token guardado vigente */
    private $savedToken = null;

    /** @var Webservice */
    private $webservice;

    protected function setUp(): void
    {
        $helper = $this->createMock(HelperHop::class);
        $helper->method('getClientId')->willReturn('client');
        $helper->method('getSellerCode')->willReturn('MENZE');
        $helper->method('getProductivo')->willReturn(false);
        $helper->method('getPickupPointsApiVersion')->willReturn('v2');

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
        $tokenCollection->method('getFirstItem')->willReturnCallback(fn () => $this->savedToken ?? new DataObject());
        $tokenCollectionFactory = $this->createMock(TokenCollectionFactory::class);
        $tokenCollectionFactory->method('create')->willReturn($tokenCollection);
        $tokenFactory = $this->createMock(TokenFactory::class);
        $tokenFactory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(\Hop\Envios\Model\Token::class)->disableOriginalConstructor()->onlyMethods(['save'])->getMock()
        );

        $credentialState = $this->createMock(CredentialState::class);
        $credentialState->method('record')->willReturnCallback(function ($key, $state) {
            $this->recorded[] = $state;
        });
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
            $this->createMock(OrderPickupPointRepository::class),
            $factory,
            $this->createMock(HopEnviosShipmentRepository::class),
            $credentialState,
            $accountResolver
        );
        $this->webservice->setStoreId(1);
    }

    private function rejectedLogin(): array
    {
        return [401, '{"errors":[{"status":401,"detail":"Nombre de usuario y\/o contraseña incorrecta","data":null}]}'];
    }

    private function okLogin(): array
    {
        return [200, '{"token_type":"Bearer","access_token":"abc","expires_in":3600000}'];
    }

    public function testRejectedCredentialsCutAfterFirstCall(): void
    {
        $this->responses = [$this->rejectedLogin()];

        // Dos cotizaciones en la misma carga de página.
        $this->assertFalse($this->webservice->isSellerActive());
        $this->assertFalse($this->webservice->isSellerActive());

        $this->assertCount(1, $this->calls, 'con credenciales rechazadas, como máximo una llamada a Hop');
        $this->assertTrue($this->webservice->isCredentialsRejected());
        $this->assertSame(Webservice::LOGIN_REJECTED, $this->webservice->getLastLoginResult());
        $this->assertSame(401, $this->webservice->getLastStatus());
        $this->assertSame([CredentialState::REJECTED], $this->recorded);
    }

    public function testRejectedWhenSavedTokenStopsWorking(): void
    {
        // Token guardado vigente: no hay inicio de sesión hasta que Hop responde 401.
        $this->savedToken = new DataObject(['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'expires_in' => 3600, 'access_token' => 'viejo']);
        $this->responses = [[401, '{"message":"Authorization fail"}'], $this->rejectedLogin()];

        $this->assertFalse($this->webservice->isSellerActive());
        $this->assertFalse($this->webservice->isSellerActive());

        $this->assertCount(2, $this->calls, 'la llamada con el token viejo y un solo inicio de sesión');
        $this->assertTrue($this->webservice->isCredentialsRejected());
    }

    public function testNoResponseIsNotRejection(): void
    {
        $this->responses = [new \Exception('Operation timed out')];
        $this->webservice->isSellerActive();

        $this->assertFalse($this->webservice->isCredentialsRejected());
        $this->assertSame(Webservice::LOGIN_UNREACHABLE, $this->webservice->getLastLoginResult());
        $this->assertSame([], $this->recorded, 'sin respuesta no se registra nada sobre las credenciales');
    }

    public function testServerErrorIsNotRejection(): void
    {
        $this->responses = [[503, '<html>Service Unavailable</html>']];
        $this->webservice->isSellerActive();

        $this->assertFalse($this->webservice->isCredentialsRejected());
        $this->assertSame(Webservice::LOGIN_UNREACHABLE, $this->webservice->getLastLoginResult());
        $this->assertSame([], $this->recorded);
    }

    /**
     * @return array [HTTP, cuerpo]
     */
    public static function notRejections(): array
    {
        return [
            'demasiados pedidos' => [429, '{"message":"Too Many Attempts."}'],
            'intermediario prohíbe' => [403, '{"message":"Forbidden"}'],
            'no encontrado' => [404, '{"message":"Not Found"}'],
            'respuesta que no es JSON' => [200, '<html></html>'],
        ];
    }

    /**
     * @dataProvider notRejections
     */
    public function testOnlyRealRejectionsCountAsRejected($status, $body): void
    {
        $this->responses = [[$status, $body]];
        $this->webservice->isSellerActive();

        $this->assertFalse($this->webservice->isCredentialsRejected());
        $this->assertSame(Webservice::LOGIN_UNREACHABLE, $this->webservice->getLastLoginResult());
        $this->assertSame([], $this->recorded);
    }

    public function testValidationErrorsAreRejection(): void
    {
        $this->responses = [[422, '{"errors":[{"status":422,"detail":"El campo client id es obligatorio","data":null}]}']];
        $this->webservice->isSellerActive();
        $this->assertTrue($this->webservice->isCredentialsRejected());
    }

    public function testExpiredTokenWithHopDownIsNoResponse(): void
    {
        $this->savedToken = new DataObject(['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'expires_in' => 3600, 'access_token' => 'viejo']);
        $this->responses = [[401, '{"message":"Authorization fail"}'], [503, 'Service Unavailable']];

        $this->assertFalse($this->webservice->isSellerActive());
        $this->assertSame(0, $this->webservice->getLastStatus(), 'se reintenta por tiempo, como un error del servidor');
        $this->assertFalse($this->webservice->isCredentialsRejected());
        $this->assertCount(2, $this->calls);
    }

    /**
     * @return array [respuesta del vendedor, estado esperado]
     */
    public static function sellerCases(): array
    {
        return [
            'activo' => [[200, '{"data":{"status":"active"}}'], 'active'],
            'bloqueado' => [[200, '{"data":{"status":"bloqueado"}}'], 'bloqueado'],
            'no existe' => [[404, '{"message":"Not Found"}'], 'not_found'],
            'llamada rechazada' => [[403, '{"message":"Forbidden"}'], null],
            'demasiados pedidos' => [[429, '{"message":"Too Many Attempts."}'], null],
        ];
    }

    /**
     * @dataProvider sellerCases
     */
    public function testSellerStatus($sellerResponse, $expected): void
    {
        $this->responses = [$this->okLogin(), $sellerResponse];
        $this->assertSame($expected, $this->webservice->getSellerStatus());
    }

    public function testSuccessfulLoginRecordsOk(): void
    {
        $this->responses = [$this->okLogin(), [200, '{"data":{"status":"active"}}']];
        $this->assertTrue($this->webservice->isSellerActive());
        $this->assertSame([CredentialState::OK], $this->recorded);
    }

    public function testSavedTokenProvesNothing(): void
    {
        $this->savedToken = new DataObject(['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'expires_in' => 3600, 'access_token' => 'vigente']);
        $this->responses = [[200, '{"data":{"status":"active"}}']];
        $this->assertTrue($this->webservice->isSellerActive());
        $this->assertSame([], $this->recorded);
    }

    public function testCheckCredentialsLogsInAgainIgnoringSavedToken(): void
    {
        $this->savedToken = new DataObject(['id' => 1, 'created_at' => date('Y-m-d H:i:s'), 'expires_in' => 3600, 'access_token' => 'vigente']);
        $this->responses = [$this->rejectedLogin()];

        $this->assertSame(Webservice::LOGIN_REJECTED, $this->webservice->checkCredentials());
        $this->assertCount(1, $this->calls);
        $this->assertStringContainsString('/login?', $this->calls[0]);
    }

    public function testCutIsResetByCheckAndStoreChange(): void
    {
        $this->responses = [$this->rejectedLogin(), $this->okLogin()];
        $this->webservice->isSellerActive();
        $this->assertTrue($this->webservice->isCredentialsRejected());

        $this->assertSame(Webservice::LOGIN_OK, $this->webservice->checkCredentials());
        $this->assertFalse($this->webservice->isCredentialsRejected());

        $this->responses = [$this->rejectedLogin()];
        $this->webservice->setStoreId(2);
        $this->webservice->isSellerActive();
        $this->assertTrue($this->webservice->isCredentialsRejected());
        $this->webservice->setStoreId(3);
        $this->assertFalse($this->webservice->isCredentialsRejected(), 'otra vista de tienda puede tener otra cuenta');
    }
}
