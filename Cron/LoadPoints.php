<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\PointFactory;
use Hop\Envios\Model\ResourceModel\Point as PointResource;
use Hop\Envios\Helper\Data as HelperData;
use Hop\Envios\Model\Webservice;

class LoadPoints
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var PointFactory
     */
    protected $PointFactory;

    /**
     * @var PointResource
     */
    protected $PointResource;

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
                    $apiData = $this->webservice->getPickupPoints($zipCode, true);
                    $point->setPointData(json_encode($apiData));
                    $point->save();
                }
            $this->logger->info('Load points cron job completed successfully.');
        } catch (\Exception $e) {
            $this->logger->error('Error during the load points cron job: ' . $e->getMessage());
        }
    }
}
