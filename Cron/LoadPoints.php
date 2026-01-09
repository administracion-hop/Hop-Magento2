<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Helper\Data as HelperData;
use Hop\Envios\Model\Webservice;

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

    public function __construct(
        LoggerInterface $logger,
        PointCollectionFactory $pointCollectionFactory,
        HelperData $helper,
        Webservice $webservice
    ) {
        $this->logger = $logger;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->helper = $helper;
        $this->webservice = $webservice;
    }

    public function execute()
    {
        $this->logger->info(__('Starting the load points cron job.'));
        try {
            $pointCollection = $this->pointCollectionFactory->create();
            foreach ($pointCollection as $point) {
                $zipCode = null;
                try {
                    $zipCode = $point->getZipCode();
                    $this->webservice->getPickupPoints($zipCode, true);
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
