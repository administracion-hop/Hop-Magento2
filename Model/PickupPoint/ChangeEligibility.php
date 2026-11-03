<?php

namespace Hop\Envios\Model\PickupPoint;

use Magento\Sales\Model\Order;

/**
 * Decides whether a buyer can change the Hop pickup point of one of their orders.
 */
class ChangeEligibility
{
    const OK = 'ok';
    const NOT_OWNER = 'not_owner';
    const NOT_HOP = 'not_hop';
    const CLOSED_STATE = 'closed_state';
    const DISPATCHED = 'dispatched';

    const HOP_SHIPPING_METHOD = 'hop_hop';

    /**
     * @var DispatchStatus
     */
    protected $dispatchStatus;

    /**
     * @param DispatchStatus $dispatchStatus
     */
    public function __construct(
        DispatchStatus $dispatchStatus
    ) {
        $this->dispatchStatus = $dispatchStatus;
    }

    /**
     * @param Order $order
     * @param int $customerId
     * @return string One of the class constants
     */
    public function check(Order $order, int $customerId): string
    {
        if (!$order->getId() || !$customerId || (int)$order->getCustomerId() !== $customerId) {
            return self::NOT_OWNER;
        }
        if ($order->getShippingMethod() !== self::HOP_SHIPPING_METHOD) {
            return self::NOT_HOP;
        }
        if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            return self::CLOSED_STATE;
        }
        if ($this->dispatchStatus->hasDispatchStarted((int)$order->getId())) {
            return self::DISPATCHED;
        }
        return self::OK;
    }

    /**
     * @param Order $order
     * @param int $customerId
     * @return bool
     */
    public function canChange(Order $order, int $customerId): bool
    {
        return $this->check($order, $customerId) === self::OK;
    }
}
