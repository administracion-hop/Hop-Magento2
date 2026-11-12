<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\Checks;
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

    /** @var Runner */
    private $runner;

    protected function setUp(): void
    {
        $this->hop = ['login' => Webservice::LOGIN_OK, 'seller' => 'active', 'points' => [(object)['id' => 99, 'zip_code' => '1414', 'reference_name' => 'Kiosco']], 'price' => 1500.0, 'status' => 200];
        $this->config = ['origin' => '1414', 'size_category' => '', 'country' => 'AR'];

        $webservice = $this->createMock(Webservice::class);
        $webservice->method('checkCredentials')->willReturnCallback(fn () => $this->hop['login']);
        $webservice->method('getLastLoginError')->willReturn('Nombre de usuario y/o contraseña incorrecta');
        $webservice->method('getSellerStatus')->willReturnCallback(fn () => $this->hop['seller']);
        $webservice->method('getPickupPoints')->willReturnCallback(fn () => (object)['data' => $this->hop['points']]);
        $webservice->method('estimatePrice')->willReturnCallback(fn () => $this->hop['price']);
        $webservice->method('getLastStatus')->willReturnCallback(fn () => $this->hop['status']);

        $resolver = $this->createMock(AccountResolver::class);
        $resolver->method('getAccounts')->willReturn(['acct:a' => [1]]);
        $resolver->method('getActiveStoreIds')->willReturn([1]);

        $storage = $this->createMock(ResultStorage::class);
        $storage->method('save')->willReturnCallback(function ($scope, $code, $status, $message = null) {
            $this->saved[$scope . '|' . $code] = [$status, $message];
        });
        $storage->method('delete')->willReturnCallback(function ($scope, $code) {
            $this->deleted[] = $scope . '|' . $code;
        });
        $storage->method('loadAll')->willReturn([]);

        $helper = $this->createMock(HelperHop::class);
        $helper->method('getOriginZipcode')->willReturnCallback(fn () => $this->config['origin']);
        $helper->method('getSizeCategory')->willReturnCallback(fn () => $this->config['size_category']);
        $helper->method('getStoreCountry')->willReturnCallback(fn () => $this->config['country']);
        $helper->method('getSellerCode')->willReturn('MENZE');

        $this->runner = $this->getMockBuilder(Runner::class)
            ->setConstructorArgs([$webservice, $resolver, $storage, $helper, $this->createMock(ProductCollectionFactory::class), $this->createMock(LoggerInterface::class)])
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
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('acct:a|seller'));
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|test_quote'));
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|measures'));
    }

    public function testRejectedCredentials(): void
    {
        $this->hop['login'] = Webservice::LOGIN_REJECTED;
        $this->runner->run();
        $this->assertArrayNotHasKey('acct:a|credentials', $this->saved, 'la escribe solo CredentialState');
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('acct:a|connection'), 'Hop respondió');
        $this->assertContains('acct:a|seller', $this->deleted);
        $this->assertContains('store:1|test_quote', $this->deleted);
    }

    public function testInactiveSeller(): void
    {
        $this->hop['seller'] = 'bloqueado';
        $this->runner->run();
        $this->assertSame(Checks::SEVERITY_ERROR, $this->savedStatus('acct:a|seller'));
        $this->assertStringContainsString('bloqueado', $this->saved['acct:a|seller'][1]);
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

    public function testSizeCategoryMakesMeasuresOk(): void
    {
        $this->withoutMeasures = 12;
        $this->config['size_category'] = '3';
        $this->runner->run();
        $this->assertSame(Checks::STATUS_OK, $this->savedStatus('store:1|measures'));
    }
}
