<?php

namespace Hop\Envios\Test\Unit\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Model\Health\Checks;
use Hop\Envios\Model\Health\DbChecks;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/**
 * Revisiones 6 (tarea programada) y 7 (avisos de estado).
 */
class DbChecksTest extends TestCase
{
    /** @var mixed lo que devuelve la consulta */
    private $queryResult;

    /** @var bool[] avisos de estado activados por vista de tienda */
    private $notifications = [];

    /** @var DbChecks */
    private $checks;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        foreach (['from', 'where', 'join', 'limit'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturnCallback(fn () => $this->queryResult);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $helper = $this->createMock(HelperHop::class);
        $helper->method('getAllowNotifications')->willReturnCallback(fn ($storeId) => $this->notifications[$storeId] ?? false);

        $this->checks = new DbChecks($resource, $helper);
    }

    public function testCronNeverRan(): void
    {
        $this->queryResult = null;
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkCron()[0]);
    }

    public function testCronStopped(): void
    {
        $now = strtotime('2026-11-12 12:00:00 UTC');
        $this->queryResult = '2026-11-12 10:59:00';
        $this->assertSame(Checks::SEVERITY_ERROR, $this->checks->checkCron($now)[0]);
    }

    public function testCronRunning(): void
    {
        $now = strtotime('2026-11-12 12:00:00 UTC');
        $this->queryResult = '2026-11-12 11:59:00';
        $this->assertNull($this->checks->checkCron($now));
    }

    public function testNotificationsDisabledIsNotAProblem(): void
    {
        $this->queryResult = false;
        $this->assertNull($this->checks->checkNotifications([1, 2]));
    }

    public function testNotificationsWithoutIntegration(): void
    {
        $this->notifications = [2 => true];
        $this->queryResult = false;
        $result = $this->checks->checkNotifications([1, 2]);
        $this->assertSame(Checks::SEVERITY_ERROR, $result[0]);
        $this->assertSame([2], $result[2], 'solo la vista de tienda con los avisos activados');
    }

    public function testNotificationsWithIntegration(): void
    {
        $this->notifications = [1 => true];
        $this->queryResult = '1';
        $this->assertNull($this->checks->checkNotifications([1]));
    }
}
