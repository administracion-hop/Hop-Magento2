<?php

namespace Hop\Envios\Model\PickupPoint;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnvios;
use Hop\Envios\Model\HopEnviosRepository;

/**
 * Whether an order was already dispatched to Hop, i.e. its pickup point can no longer change.
 *
 * Single source of truth for the cut-off condition shared by the admin "Cambiar punto Hop"
 * button and the buyer's "Cambiar punto de retiro" in My Orders.
 */
class DispatchStatus
{
    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @param HopEnviosRepository $hopEnviosRepository
     */
    public function __construct(
        HopEnviosRepository $hopEnviosRepository
    ) {
        $this->hopEnviosRepository = $hopEnviosRepository;
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
