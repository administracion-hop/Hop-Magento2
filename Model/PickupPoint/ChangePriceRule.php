<?php

namespace Hop\Envios\Model\PickupPoint;

use Magento\Sales\Model\Order;
use Magento\Tax\Model\Config as TaxConfig;

/**
 * A buyer can only move an order to a pickup point whose Hop tariff is exactly the one quoted
 * at checkout, unless shipping was free. A partial shipping discount (coupon) does not count:
 * the new quote is compared against the tariff before the discount, and the buyer keeps the
 * discount they got (PO decision 2026-11-03).
 */
class ChangePriceRule
{
    const FREESHIPPING_METHOD = 'freeshipping_freeshipping';

    /**
     * @var TaxConfig
     */
    protected $taxConfig;

    /**
     * @param TaxConfig $taxConfig
     */
    public function __construct(
        TaxConfig $taxConfig
    ) {
        $this->taxConfig = $taxConfig;
    }

    /**
     * Hop tariff as quoted at checkout, in base currency and before discounts, in the same
     * terms the carrier price was entered: Magento takes the carrier price as tax-inclusive
     * when "Shipping Prices include tax" is on for the store, and stores it in
     * base_shipping_incl_tax; otherwise it is base_shipping_amount.
     *
     * @param Order $order
     * @return float
     */
    public function getTariffAmount(Order $order): float
    {
        $amount = $this->taxConfig->shippingPriceIncludesTax($order->getStoreId())
            ? $order->getBaseShippingInclTax()
            : $order->getBaseShippingAmount();
        return round((float)$amount, 2);
    }

    /**
     * Shipping as charged in the checkout: base currency, after discounts, without tax.
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
    public function matchesTariff(float $quotedPrice, Order $order): bool
    {
        return round($quotedPrice, 2) === $this->getTariffAmount($order);
    }
}
