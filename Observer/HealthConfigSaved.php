<?php

namespace Hop\Envios\Observer;

use Hop\Envios\Model\Health\Runner;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Al guardar la configuración de Hop (secciones carriers y shipping) corre en el momento las
 * revisiones contra Hop y el catálogo de las vistas de tienda alcanzadas por ese guardado.
 * Solo si cambió algo de Hop: guardar, por ejemplo, la tarifa plana no llama a Hop.
 */
class HealthConfigSaved implements ObserverInterface
{
    /**
     * @var Runner
     */
    private $runner;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Runner $runner
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(Runner $runner, StoreManagerInterface $storeManager, LoggerInterface $logger)
    {
        $this->runner = $runner;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        if (!$this->hasHopChanges((array)$observer->getEvent()->getData('changed_paths'))) {
            return;
        }
        try {
            $this->runner->run($this->getStoreIds($observer->getEvent()->getData('website'), $observer->getEvent()->getData('store')));
        } catch (\Throwable $e) {
            // Una revisión que falla nunca puede impedir guardar la configuración.
            $this->logger->error('Hop: error en la revisión al guardar la configuración: ' . $e->getMessage());
        }
    }

    /**
     * @param string[] $changedPaths
     * @return bool
     */
    private function hasHopChanges(array $changedPaths)
    {
        foreach ($changedPaths as $path) {
            if (strpos((string)$path, 'carriers/hop/') === 0 || strpos((string)$path, 'shipping/hop/') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vistas de tienda alcanzadas por el guardado: la vista, las del sitio o todas.
     *
     * @param string|int|null $website
     * @param string|int|null $store
     * @return int[]|null null = todas
     */
    private function getStoreIds($website, $store)
    {
        if ($store !== null && $store !== '') {
            return [(int)$this->storeManager->getStore($store)->getId()];
        }
        if ($website !== null && $website !== '') {
            return array_map('intval', $this->storeManager->getWebsite($website)->getStoreIds());
        }
        return null;
    }
}
