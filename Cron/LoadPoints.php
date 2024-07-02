<?php

namespace Improntus\Hop\Cron;

use Psr\Log\LoggerInterface;
use Improntus\Hop\Model\PointFactory;
use Improntus\Hop\Model\ResourceModel\Point as PointResource;
use Improntus\Hop\Helper\Data as HelperData;
use Improntus\Hop\Model\Webservice;

class LoadPoints
{
    protected $logger;
    protected $PointFactory;
    protected $PointResource;
    protected $helper;
    protected $webservice;

    public function __construct(
        LoggerInterface $logger,
        PointFactory $PointFactory,
        PointResource $PointResource,
        HelperData $helper,
        Webservice $webservice
    ) {
        $this->logger = $logger;
        $this->PointFactory = $PointFactory;
        $this->PointResource = $PointResource;
        $this->helper = $helper;
        $this->webservice = $webservice;
    }

    public function execute()
    {
        $this->logger->info('Starting the load points cron job.');
        try {
                $points = $this->PointFactory->create()->getCollection();
                foreach ($points as $key => $point) {
                    $zipCode = $point->getZipCode();
                    $apiData = $this->webservice->getPickupPoints($zipCode);
                        $point->setPointData(json_encode($apiData));
                        $point->save();
                }
            $this->logger->info('Load points cron job completed successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error during the load points cron job: ' . $e->getMessage());
        }
    }
}
