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
 * La sección de envíos (shipping), donde están las credenciales de Hop, revisa siempre al
 * guardarla, aunque no cambie nada: es lo que hace alguien después de que Hop le desbloquea la
 * cuenta o vuelve de una caída. La de métodos de envío (carriers) tiene todos los transportistas,
 * así que revisa solo si cambió algo de Hop: guardar, por ejemplo, la tarifa plana no llama a Hop.
 */
class HealthConfigSaved implements ObserverInterface
{
    /** Tope para toda la revisión al guardar: lo que no alcance lo revisa la tarea de 6 horas. */
    const TIME_BUDGET_SECONDS = 25;

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
        $isShippingSection = substr((string)$observer->getEvent()->getName(), -strlen('_section_shipping')) === '_section_shipping';
        if (!$isShippingSection && !$this->hasHopChanges((array)$observer->getEvent()->getData('changed_paths'))) {
            return;
        }
        try {
            $this->runner->run(
                $this->getStoreIds($observer->getEvent()->getData('website'), $observer->getEvent()->getData('store')),
                self::TIME_BUDGET_SECONDS
            );
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
