<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\Health\AccountResolver;
use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\ConfigChecks;
use Hop\Envios\Model\Health\DbChecks;
use Hop\Envios\Model\Health\Report;
use Hop\Envios\Model\Health\ResultStorage;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * Lo que muestra la franja: agrupado por vista de tienda, sin cuentas viejas y sin lo resuelto.
 */
class ReportTest extends TestCase
{
    /** @var array */
    private $rows = [];

    /** @var array código => [severidad, mensaje] por vista de tienda */
    private $configProblems = [];

    /** @var string[] */
    private $deleted = [];

    /** @var int */
    private $writes = 0;

    private function report(): Report
    {
        $resolver = $this->createMock(AccountResolver::class);
        $resolver->method('getActiveStoreIds')->willReturn([1, 2]);
        $resolver->method('getAccounts')->willReturn(['acct:actual' => [1, 2]]);

        $configChecks = $this->createMock(ConfigChecks::class);
        $configChecks->method('run')->willReturnCallback(fn ($storeId) => $this->configProblems[$storeId] ?? []);

        $storage = $this->createMock(ResultStorage::class);
        $storage->method('loadAll')->willReturnCallback(fn () => $this->rows);
        $storage->method('save')->willReturnCallback(function () {
            $this->writes++;
        });
        $storage->method('delete')->willReturnCallback(function ($scope, $code) {
            $this->deleted[] = $scope . '|' . $code;
        });

        return new Report(
            $resolver,
            $configChecks,
            $this->createMock(DbChecks::class),
            $storage,
            $this->createMock(CacheInterface::class),
            new Json(),
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testNoProblemsNoBanner(): void
    {
        $this->assertSame(['errors' => 0, 'warnings' => 0], $this->report()->getCounts());
    }

    public function testDemoModeIsPerStoreView(): void
    {
        $warning = [Checks::SEVERITY_WARNING, 'Hop está en modo prueba y la tienda no está marcada como demo'];
        $this->configProblems = [2 => [Checks::DEMO_MODE => $warning]];
        $problems = $this->report()->getProblems();

        $this->assertCount(1, $problems);
        $this->assertSame([2], $problems[0]['store_ids'], 'solo la vista donde no coinciden');
        $this->assertSame(['errors' => 0, 'warnings' => 1], $this->report()->getCounts());
    }

    public function testSameProblemInSeveralStoresIsGrouped(): void
    {
        $error = [Checks::SEVERITY_ERROR, 'La tienda usa libras'];
        $this->configProblems = [1 => [Checks::WEIGHT_UNIT => $error], 2 => [Checks::WEIGHT_UNIT => $error]];
        $problems = $this->report()->getProblems();

        $this->assertCount(1, $problems);
        $this->assertSame([1, 2], $problems[0]['store_ids']);
        $this->assertNotSame('', $problems[0]['fix']);
    }

    public function testStoredResultsOfCurrentAccountsOnly(): void
    {
        $this->rows = [
            'acct:actual|credentials' => ['scope_key' => 'acct:actual', 'check_code' => 'credentials', 'status' => 'error', 'message' => 'Nombre de usuario y/o contraseña incorrecta', 'since' => '2026-11-12 10:00:00'],
            'acct:vieja|seller' => ['scope_key' => 'acct:vieja', 'check_code' => 'seller', 'status' => 'error', 'message' => 'x', 'since' => '2026-11-12 10:00:00'],
            'store:1|measures' => ['scope_key' => 'store:1', 'check_code' => 'measures', 'status' => 'ok', 'message' => null, 'since' => '2026-11-12 10:00:00'],
        ];
        $problems = $this->report()->getProblems();

        $this->assertCount(1, $problems);
        $this->assertSame(Checks::CREDENTIALS, $problems[0]['code']);
        $this->assertSame([1, 2], $problems[0]['store_ids']);
        $this->assertSame('2026-11-12 10:00:00', $problems[0]['since']);
    }

    public function testFixedConfigProblemDisappearsAndIsCleaned(): void
    {
        $this->rows = [
            'store:1|weight_unit' => ['scope_key' => 'store:1', 'check_code' => 'weight_unit', 'status' => 'error', 'message' => 'La tienda usa libras', 'since' => '2026-11-12 10:00:00'],
        ];
        $this->assertSame([], $this->report()->getProblems());
        $this->assertSame(['store:1|weight_unit'], $this->deleted);
    }

    public function testKnownProblemKeepsSinceWithoutWriting(): void
    {
        $this->configProblems = [1 => [Checks::WEIGHT_UNIT => [Checks::SEVERITY_ERROR, 'La tienda usa libras']]];
        $this->rows = [
            'store:1|weight_unit' => ['scope_key' => 'store:1', 'check_code' => 'weight_unit', 'status' => 'error', 'message' => 'La tienda usa libras', 'since' => '2026-11-10 08:00:00'],
        ];
        $problems = $this->report()->getProblems();

        $this->assertSame('2026-11-10 08:00:00', $problems[0]['since']);
        $this->assertSame(0, $this->writes, 'mostrar la franja no escribe si nada cambió');
    }
}
