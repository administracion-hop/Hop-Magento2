<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Model\HopEnviosShipmentRepository;
use Hop\Envios\Model\Shipping\NativeLabelGenerator;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Retries native shipping_label generation for Hop shipments that already have a real
 * tracking/label from Hop but whose PDF wasn't downloadable yet the first time (Hop's
 * label_url routinely isn't live on S3/CloudFront until roughly a minute after dispatch —
 * see NativeLabelGenerator). Runs every minute; a shipment naturally drops out of the
 * candidate set as soon as shipping_label gets set.
 */
class GenerateShippingLabels
{
    /**
     * @var HopEnviosShipmentRepository
     */
    protected $hopEnviosShipmentRepository;

    /**
     * @var ShipmentRepositoryInterface
     */
    protected $shipmentRepository;

    /**
     * @var NativeLabelGenerator
     */
    protected $nativeLabelGenerator;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param HopEnviosShipmentRepository $hopEnviosShipmentRepository
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param NativeLabelGenerator $nativeLabelGenerator
     * @param LoggerInterface $logger
     */
    public function __construct(
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        ShipmentRepositoryInterface $shipmentRepository,
        NativeLabelGenerator $nativeLabelGenerator,
        LoggerInterface $logger
    ) {
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->shipmentRepository = $shipmentRepository;
        $this->nativeLabelGenerator = $nativeLabelGenerator;
        $this->logger = $logger;
    }

    /**
     * Execute the cron job.
     */
    public function execute()
    {
        foreach ($this->hopEnviosShipmentRepository->getCompletedWithLabel() as $record) {
            $shipmentId = (int)$record->getShipmentId();
            try {
                $shipment = $this->shipmentRepository->get($shipmentId);
            } catch (\Exception $e) {
                continue;
            }

            if ($shipment->getShippingLabel()) {
                continue;
            }

            if ($this->nativeLabelGenerator->generate($shipment)) {
                $this->logger->info(__('Hop: shipping_label generado (retry cron) para shipment %1', $shipmentId));
            }
        }
    }
}
