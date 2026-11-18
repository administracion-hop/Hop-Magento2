<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\CredentialState;
use Hop\Envios\Model\Health\ResultStorage;
use Hop\Envios\Model\Health\Runner;
use Hop\Envios\Model\Webservice;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Revisiones 1, 5 y 9: cada una con su caso de problema y su caso correcto.
 */
class RunnerTest extends TestCase
{
    /** @var array resultados guardados: "scope|código" => [estado, mensaje] */
    private $saved = [];

    /** @var string[] filas borradas */
    private $deleted = [];

    /** @var array */
    private $hop = [];

    /** @var array */
    private $config = [];

    /** @var int */
    private $withoutMeasures = 0;

    /** @var array */
    private $accounts = ['acct:a' => [1]];

    /** @var array código de vendedor por vista de tienda */
    private $sellerCodes = [];

    /** @var array estado de Hop por código de vendedor */
    private $sellerByCode = [];

    /** @var int consultas del vendedor a Hop */
    private $sellerCalls = 0;

    /** @var int[] tiempos de espera fijados en el cliente de Hop */
    private $timeouts = [];

    /** @var array filas que devuelve la tabla */
    private $rows = [];

    /** @var string[] cuentas olvidadas en la caché */
    private $forgotten = [];

    /** @var Runner */
    private $runner;

    protected function setUp(): void
    {
        $this->hop = ['login' => Webservice::LOGIN_OK, 'seller' => 'active', 'points' => [(object)['id' => 99, 'zip_code' => '1414', 'reference_name' => 'Kiosco']], 'price' => 1500.0, 'status' => 200];
        $this->config = ['origin' => '1414', 'size_category' => '', 'country' => 'AR'];

        $webservice = $this->createMock(Webservice::class);
        $webservice->method('checkCredentials')->willReturnCallback(fn () => $this->hop['login']);
        $webservice->method('getLastLoginError')->willReturn('Nombre de usuario y/o contraseña incorrecta');
        $webservice->method('setRequestTimeout')->willReturnCallback(function ($seconds) {
            $this->timeouts[] = $seconds;
        });
        $currentStore = null;
        $webservice->method('setStoreId')->willReturnCallback(function ($storeId) use (&$currentStore) {
            $currentStore = $storeId;
        });
        $webservice->method('getSellerStatus')->willReturnCallback(function () use (&$currentStore) {
            $this->sellerCalls++;
            $code = $this->sellerCodes[$currentStore] ?? 'MENZE';
            return $this->sellerByCode[$code] ?? $this->hop['seller'];
        });
        $webservice->method('getPickupPoints')->willReturnCallback(fn () => (object)['data' => $this->hop['points']]);
        $webservice->method('estimatePrice')->willReturnCallback(fn () => $this->hop['price']);
        $webservice->method('getLastStatus')->willReturnCallback(fn () => $this->hop['status']);

        $resolver = $this->createMock(AccountResolver::class);
        $resolver->method('getAccounts')->willReturnCallback(fn () => $this->accounts);
        $resolver->method('getActiveStoreIds')->willReturnCallback(fn () => array_merge(...array_values($this->accounts ?: ['x' => [1]])));

        $storage = $this->createMock(ResultStorage::class);
        $storage->method('save')->willReturnCallback(function ($scope, $code, $status, $message = null) {
            $this->saved[$scope . '|' . $code] = [$status, $message];
        });
        $storage->method('delete')->willReturnCallback(function ($scope, $code) {
            $this->deleted[] = $scope . '|' . $code;
        });
        $storage->method('loadAll')->willReturnCallback(fn () => $this->rows);

        $helper = $this->createMock(HelperHop::class);
        $helper->method('getOriginZipcode')->willReturnCallback(fn () => $this->config['origin']);
        $helper->method('getSizeCategory')->willReturnCallback(fn () => $this->config['size_category']);
        $helper->method('getStoreCountry')->willReturnCallback(fn () => $this->config['country']);
        $helper->method('getSellerCode')->willReturnCallback(fn ($storeId) => $this->sellerCodes[$storeId] ?? 'MENZE');

        $credentialState = $this->createMock(CredentialState::class);
        $credentialState->method('forget')->willReturnCallback(function ($key) {
            $this->forgotten[] = $key;
        });

        $this->runner = $this->getMockBuilder(Runner::class)
            ->setConstructorArgs([$webservice, $resolver, $storage, $helper, $this->createMock(ProductCollectionFactory::class), $this->createMock(LoggerInterface::class), $credentialState])
            ->onlyMethods(['countProductsWithoutMeasures'])
            ->getMock();
        $this->runner->method('countProductsWithoutMeasures')->willReturnCallback(fn () => $this->withoutMeasures);
    }

    private function savedStatus($key)
    {
        return $this->saved[$key][0] ?? null;
    }

    public function testEverythingOk(): void
    {
        $this->runner->run();
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('acct:a|connection'));
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|seller'));
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|test_quote'));
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|measures'));
    }

    public function testRejectedCredentials(): void
    {
        $this->hop['login'] = Webservice::LOGIN_REJECTED;
        $this->runner->run();
        $this->assertArrayNotHasKey('acct:a|credentials', $this->saved, 'la escribe solo CredentialState');
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('acct:a|connection'), 'Hop respondió');
        $this->assertContains('store:1|seller', $this->deleted);
        $this->assertContains('store:1|test_quote', $this->deleted);
    }

    public function testInactiveSeller(): void
    {
        $this->hop['seller'] = 'bloqueado';
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_ERROR, $this->savedStatus('store:1|seller'));
        $this->assertStringContainsString('bloqueado', $this->saved['store:1|seller'][1]);
    }

    public function testSellerIsCheckedPerStoreView(): void
    {
        // Dos vistas con las mismas credenciales y distinto código de vendedor.
        $this->accounts = ['acct:a' => [1, 2]];
        $this->sellerCodes = [1 => 'BUENO', 2 => 'MALO'];
        $this->sellerByCode = ['BUENO' => 'active', 'MALO' => 'not_found'];
        $this->runner->run();
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|seller'));
        $this->assertSame(Checks::SEVERITY_ERROR, $this->savedStatus('store:2|seller'));
        $this->assertStringContainsString('MALO', $this->saved['store:2|seller'][1]);
    }

    public function testSameSellerCodeIsCheckedOnce(): void
    {
        $this->accounts = ['acct:a' => [1, 2]];
        $this->runner->run();
        $this->assertSame(1, $this->sellerCalls);
    }

    public function testEmptySellerCodeDoesNotCallHop(): void
    {
        $this->sellerCodes = [1 => ''];
        $this->runner->run();
        $this->assertSame(0, $this->sellerCalls);
        $this->assertContains('store:1|seller', $this->deleted, 'ya lo avisa Datos para despachar');
    }

    public function testNoResponseIsWarningAndKeepsCredentials(): void
    {
        $this->hop['login'] = Webservice::LOGIN_UNREACHABLE;
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_WARNING, $this->savedStatus('acct:a|connection'));
        $this->assertSame('No se pudo verificar la conexión con Hop.', $this->saved['acct:a|connection'][1]);
        $this->assertArrayNotHasKey('acct:a|credentials', $this->saved, 'no se informa como credenciales rechazadas');
    }

    public function testQuoteFails(): void
    {
        $this->hop['price'] = false;
        $this->hop['status'] = 422;
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_ERROR, $this->savedStatus('store:1|test_quote'));
    }

    public function testNoPointsNearOriginIsWarning(): void
    {
        $this->hop['points'] = [];
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_WARNING, $this->savedStatus('store:1|test_quote'));
        $this->assertStringContainsString('No se pudo probar la cotización', $this->saved['store:1|test_quote'][1]);
    }

    public function testMissingOriginZipIsError(): void
    {
        $this->config['origin'] = '';
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_ERROR, $this->savedStatus('store:1|test_quote'));
    }

    public function testPeruQuoteIsLeftForSecondStage(): void
    {
        $this->config['country'] = 'PE';
        $this->runner->run();
        $this->assertArrayNotHasKey('store:1|test_quote', $this->saved);
        $this->assertContains('store:1|test_quote', $this->deleted);
    }

    public function testProductsWithoutMeasures(): void
    {
        $this->withoutMeasures = 12;
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_WARNING, $this->savedStatus('store:1|measures'));
        $this->assertStringContainsString('12 productos', $this->saved['store:1|measures'][1]);
    }

    public function testMeasuresWithoutCredentials(): void
    {
        $this->accounts = [];
        $this->withoutMeasures = 4;
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_WARNING, $this->savedStatus('store:1|measures'), 'las medidas no dependen de Hop');
        $this->assertArrayNotHasKey('store:1|test_quote', $this->saved);
    }

    public function testStaleAccountIsForgottenInCache(): void
    {
        // La cuenta vieja deja de estar configurada: si vuelve, su estado tiene que volver a
        // escribirse, así que no puede quedar en la caché.
        $this->rows = [
            ['scope_key' => 'acct:vieja', 'check_code' => 'credentials', 'status' => 'error'],
            ['scope_key' => 'acct:a', 'check_code' => 'credentials', 'status' => 'ok'],
        ];
        $this->runner->run();
        $this->assertSame(['acct:vieja'], $this->forgotten);
    }

    public function testPartialRunDoesNotForget(): void
    {
        $this->rows = [['scope_key' => 'acct:vieja', 'check_code' => 'credentials', 'status' => 'error']];
        $this->runner->run([1]);
        $this->assertSame([], $this->forgotten, 'al guardar una sola vista de tienda no se limpia nada');
    }

    public function testTimeBudgetStopsCallingHop(): void
    {
        // Tope ya vencido: no se llama a Hop y quedan los resultados anteriores.
        $this->runner->run(null, -1);
        $this->assertSame(0, $this->sellerCalls);
        $this->assertArrayNotHasKey('acct:a|connection', $this->saved);
        $this->assertArrayNotHasKey('store:1|test_quote', $this->saved);
    }

    public function testStoreWithoutAccountDropsOldHopResults(): void
    {
        // Se borraron las credenciales de la vista: los avisos viejos de vendedor y cotización
        // no pueden quedar en la franja junto a "Faltan credenciales".
        $this->accounts = [];
        $this->runner->run();
        $this->assertContains('store:1|seller', $this->deleted);
        $this->assertContains('store:1|test_quote', $this->deleted);
    }

    public function testTooManyRequestsIsNotAConfigurationError(): void
    {
        $this->hop['price'] = false;
        $this->hop['status'] = 429;
        $this->runner->run();
        $this->assertArrayNotHasKey('store:1|test_quote', $this->saved, 'un 429 no dice nada de la configuración');
    }

    public function testEachCallWaitsAtMostWhatIsLeft(): void
    {
        $this->runner->run(null, 3);
        $calls = array_slice($this->timeouts, 1, -1); // sin el inicial ni el que lo limpia al final
        $this->assertNotEmpty($calls);
        foreach ($calls as $seconds) {
            $this->assertLessThanOrEqual(3, $seconds);
            $this->assertGreaterThanOrEqual(1, $seconds);
        }
    }

    public function testSizeCategoryMakesMeasuresOk(): void
    {
        $this->withoutMeasures = 12;
        $this->config['size_category'] = '3';
        $this->runner->run();
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|measures'));
    }
}
