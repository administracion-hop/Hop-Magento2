<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Model\ResourceModel\Point as PointResource;
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
     * @var PointResource
     */
    protected $pointResource;

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
        PointResource $pointResource,
        HelperData $helper,
        Webservice $webservice,
        StoreManagerInterface $storeManager
    ) {
        $this->logger = $logger;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->pointResource = $pointResource;
        $this->helper = $helper;
        $this->webservice = $webservice;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        $this->logger->info(__('Starting the load points cron job.'));
        try {
            $this->cacheCoordinatesForPointsWithoutThem();

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

    /**
     * For all points without coordinates, fetches postal codes once per country
     * and persists lat/lng in bulk to avoid repeated API calls during getPickupPoints().
     */
    private function cacheCoordinatesForPointsWithoutThem()
    {
        $collection = $this->pointCollectionFactory->create();
        $collection->addFieldToFilter(
            ['latitude', 'latitude'],
            [['null' => true], ['eq' => '']]
        );

        if (!$collection->getSize()) {
            return;
        }

        $byCountry = [];
        foreach ($collection as $point) {
            $countryCode = $point->getCountryCode() ?: 'AR';
            $byCountry[$countryCode][] = $point;
        }

        foreach ($byCountry as $countryCode => $points) {
            $postalCodes = $this->webservice->fetchAllPostalCodes($countryCode);
            if (empty($postalCodes)) {
                $this->logger->error(__('Could not fetch postal codes for country %1', $countryCode));
                continue;
            }

            $coordsByZip = [];
            foreach ($postalCodes as $entry) {
                if (isset($entry['cp'], $entry['lat'], $entry['lng'])) {
                    $coordsByZip[(string)$entry['cp']] = ['lat' => $entry['lat'], 'lng' => $entry['lng']];
                }
            }

            foreach ($points as $point) {
                $zipCode = (string)$point->getZipCode();
                if (!isset($coordsByZip[$zipCode])) {
                    $this->logger->error(__('No coordinates found for zip code %1', $zipCode));
                    continue;
                }
                try {
                    $point->setLatitude($coordsByZip[$zipCode]['lat']);
                    $point->setLongitude($coordsByZip[$zipCode]['lng']);
                    $this->pointResource->save($point);
                } catch (\Exception $e) {
                    $this->logger->error(__('Failed to save coordinates for zip code %1: %2', $zipCode, $e->getMessage()));
                }
            }
        }
    }
}
