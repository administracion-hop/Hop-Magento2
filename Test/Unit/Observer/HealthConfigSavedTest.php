<?php

namespace Hop\Envios\Test\Unit\Observer;

use Hop\Envios\Model\Health\Runner;
use Hop\Envios\Observer\HealthConfigSaved;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Guardar la configuración de envíos solo llama a Hop si cambió algo de Hop.
 */
class HealthConfigSavedTest extends TestCase
{
    /** @var array */
    private $runs = [];

    private function save(array $changedPaths, $store = null, $section = 'carriers')
    {
        $runner = $this->createMock(Runner::class);
        $runner->method('run')->willReturnCallback(function ($storeIds = null) {
            $this->runs[] = $storeIds;
        });
        $storeEntity = $this->createMock(StoreInterface::class);
        $storeEntity->method('getId')->willReturn(2);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($storeEntity);

        $event = new Event(['changed_paths' => $changedPaths, 'website' => '', 'store' => $store]);
        $event->setName('admin_system_config_changed_section_' . $section);
        (new HealthConfigSaved($runner, $storeManager, $this->createMock(LoggerInterface::class)))
            ->execute(new Observer(['event' => $event]));
    }

    public function testOtherCarrierDoesNotCallHop(): void
    {
        $this->save(['carriers/flatrate/price']);
        $this->assertSame([], $this->runs);
    }

    public function testSavingShippingSectionAlwaysChecks(): void
    {
        // Después de que Hop desbloquea la cuenta, alguien aprieta Guardar sin cambiar nada.
        $this->save([], null, 'shipping');
        $this->assertSame([null], $this->runs);
    }

    public function testHopChangeRunsChecksForAllStores(): void
    {
        $this->save(['carriers/flatrate/price', 'shipping/hop/password']);
        $this->assertSame([null], $this->runs);
    }

    public function testStoreViewSaveRunsOnlyThatStore(): void
    {
        $this->save(['carriers/hop/active'], '2');
        $this->assertSame([[2]], $this->runs);
    }
}
