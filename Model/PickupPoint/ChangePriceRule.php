<?php

namespace Hop\Envios\Model\PickupPoint;

use Magento\Sales\Model\Order;

/**
 * A buyer can only move an order to a pickup point that costs exactly what they were charged
 * for shipping, unless shipping was free.
 */
class ChangePriceRule
{
    const FREESHIPPING_METHOD = 'freeshipping_freeshipping';

    /**
     * Shipping as charged in the checkout: base currency, after discounts, without tax — the
     * same terms the carrier quotes in.
     *
     * @param Order $order
     * @return float
     */
    public function getChargedAmount(Order $order): float
    {
        return round(
            (float)$order->getBaseShippingAmount() - (float)$order->getBaseShippingDiscountAmount(),
            2
        );
    }

    /**
     * Free shipping takes any point without quoting: Magento's freeshipping method, or a
     * shipping charge of 0 after discounts (cart rule or Hop's own hop_free_shipping threshold).
     *
     * @param Order $order
     * @return bool
     */
    public function isFreeShipping(Order $order): bool
    {
        return $order->getShippingMethod() === self::FREESHIPPING_METHOD
            || $this->getChargedAmount($order) <= 0.0;
    }

    /**
     * @param float $quotedPrice
     * @param Order $order
     * @return bool
     */
    public function matchesChargedAmount(float $quotedPrice, Order $order): bool
    {
        return round($quotedPrice, 2) === $this->getChargedAmount($order);
    }
}
