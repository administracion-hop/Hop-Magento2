<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Helper\Data as HelperData;
use Hop\Envios\Model\Webservice;
use Magento\Store\Model\StoreManagerInterface;

class LoadPoints
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PointCollectionFactory
     */
    protected $pointCollectionFactory;

    /**
     * @var HelperData
     */
    protected $helper;

    /**
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    public function __construct(
        LoggerInterface $logger,
        PointCollectionFactory $pointCollectionFactory,
        HelperData $helper,
        Webservice $webservice,
        StoreManagerInterface $storeManager
    ) {
        $this->logger = $logger;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->helper = $helper;
        $this->webservice = $webservice;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        $this->logger->info(__('Starting the load points cron job.'));
        try {
            $clientStoreMap = [];
            foreach ($this->storeManager->getStores() as $store) {
                $clientId = $this->helper->getClientId($store->getId());
                if (!empty($clientId) && !isset($clientStoreMap[$clientId])) {
                    $clientStoreMap[$clientId] = $store->getId();
                }
            }

            if (empty($clientStoreMap)) {
                $this->logger->info(__('No stores with client_id configured. Skipping.'));
                return;
            }

            $pointCollection = $this->pointCollectionFactory->create()
                ->addFieldToFilter('client_id', ['in' => array_keys($clientStoreMap)])
                ->setOrder('client_id', 'ASC');

            $currentClientId = null;
            foreach ($pointCollection as $point) {
                $clientId = $point->getClientId();
                if ($clientId !== $currentClientId) {
                    $currentClientId = $clientId;
                    $this->webservice->setStoreId($clientStoreMap[$clientId]);
                }
                $zipCode = null;
                try {
                    $zipCode = $point->getZipCode();
                    $countryCode = $point->getCountryCode();
                    $this->webservice->getPickupPoints($zipCode, $countryCode, true);
                } catch (\Exception $e) {
                    $this->logger->error(__('Failed to process point with zip code %1: %2', $zipCode ?? 'unknown', $e->getMessage()));
                }
            }

            $this->logger->info(__('Load points cron job completed successfully.'));
        } catch (\Exception $e) {
            $this->logger->error(__('Error during the load points cron job: ') . $e->getMessage());
        }
    }
}
