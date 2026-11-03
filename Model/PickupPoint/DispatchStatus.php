<?php

namespace Hop\Envios\Model\PickupPoint;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Whether an order was already dispatched to Hop, i.e. its pickup point can no longer change.
 *
 * Single source of truth for the cut-off condition shared by the admin "Cambiar punto Hop"
 * button and the buyer's "Cambiar punto de retiro" in My Orders.
 */
class DispatchStatus
{
    /**
     * Set by the dispatch cron right before it creates the Magento shipment and calls Hop.
     */
    const STATUS_PROCESSING = 'processing';

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var ShipmentCollectionFactory
     */
    protected $shipmentCollectionFactory;

    /**
     * @param HopEnviosRepository $hopEnviosRepository
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     */
    public function __construct(
        HopEnviosRepository $hopEnviosRepository,
        ShipmentCollectionFactory $shipmentCollectionFactory
    ) {
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
    }

    /**
     * Stricter cut-off for the buyer: besides an order already dispatched, blocks an order the
     * cron is dispatching right now (status "processing", the Hop call may already be in flight)
     * and an order with a Magento shipment (an admin is dispatching it by hand). The admin keeps
     * using isDispatched(), since an admin may still need to fix the point in those cases.
     *
     * @param int $orderId
     * @return bool
     */
    public function hasDispatchStarted(int $orderId): bool
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);
        if ($this->isRecordDispatched($hopEnvios)) {
            return true;
        }
        if ($hopEnvios && $hopEnvios->getStatusShipment() === self::STATUS_PROCESSING) {
            return true;
        }
        return $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', $orderId)
            ->getSize() > 0;
    }

    /**
     * @param int $orderId
     * @return bool
     */
    public function isDispatched(int $orderId): bool
    {
        return $this->isRecordDispatched($this->hopEnviosRepository->getByOrderId($orderId));
    }

    /**
     * Multibulto orders (2+ shipments) never populate the order-level info_hop field — each
     * shipment's tracking/label lives in its own hop_envios_shipment row instead. status_shipment
     * is the only order-level signal that's reliable for both the single-shipment and multibulto
     * paths. A multibulto with rejected bultos stays failed but is already created in Hop.
     *
     * @param HopEnvios|null $hopEnvios
     * @return bool
     */
    public function isRecordDispatched($hopEnvios): bool
    {
        return $hopEnvios && (
            $hopEnvios->getStatusShipment() === 'completed'
            || !DispatchError::isRetryable($hopEnvios->getLastErrorCode())
        );
    }
}
