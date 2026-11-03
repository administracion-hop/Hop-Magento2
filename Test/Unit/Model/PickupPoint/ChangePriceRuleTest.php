<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\PickupPoint\ChangePriceRule;
use Magento\Sales\Model\Order;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;

class ChangePriceRuleTest extends TestCase
{
    /**
     * @var ChangePriceRule
     */
    private $rule;

    /**
     * @var bool "Shipping Prices include tax" for the order's store
     */
    private $shippingIncludesTax = false;

    protected function setUp(): void
    {
        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('shippingPriceIncludesTax')->with(1)->willReturnCallback(fn () => $this->shippingIncludesTax);
        $this->rule = new ChangePriceRule($taxConfig);
    }

    /**
     * @param float|null $shipping
     * @param float|null $discount
     * @param string $method
     * @param float|null $shippingInclTax
     * @return Order
     */
    private function order($shipping, $discount = null, string $method = 'hop_hop', $shippingInclTax = null): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseShippingAmount', 'getBaseShippingDiscountAmount', 'getShippingMethod', 'getBaseShippingInclTax', 'getStoreId'])
            ->getMock();
        $order->method('getBaseShippingAmount')->willReturn($shipping);
        $order->method('getBaseShippingDiscountAmount')->willReturn($discount);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getBaseShippingInclTax')->willReturn($shippingInclTax ?? $shipping);
        $order->method('getStoreId')->willReturn(1);
        return $order;
    }

    /**
     * Prices include tax (21 %): Hop quoted 5200, Magento stored 4297.52 without tax and 5200
     * with tax. The new quote must be compared against 5200.
     */
    public function testShippingPricesIncludingTaxCompareAgainstAmountWithTax(): void
    {
        $this->shippingIncludesTax = true;
        $order = $this->order(4297.52, null, 'hop_hop', 5200);

        $this->assertSame(5200.0, $this->rule->getTariffAmount($order));
        $this->assertTrue($this->rule->matchesTariff(5200, $order));
        $this->assertFalse($this->rule->matchesTariff(4297.52, $order));
    }

    public function testShippingPricesWithoutTaxCompareAgainstAmountWithoutTax(): void
    {
        $order = $this->order(5200, null, 'hop_hop', 6292);

        $this->assertSame(5200.0, $this->rule->getTariffAmount($order));
        $this->assertTrue($this->rule->matchesTariff(5200, $order));
    }

    public function testChargedAmountIsNetOfDiscount(): void
    {
        $this->assertSame(4000.5, $this->rule->getChargedAmount($this->order(5000.5, 1000)));
    }

    public function testEqualQuoteMatches(): void
    {
        $this->assertTrue($this->rule->matchesTariff(5321.45, $this->order(5321.45)));
    }

    public function testQuoteIsRoundedToTwoDecimals(): void
    {
        $this->assertTrue($this->rule->matchesTariff(5321.4549, $this->order(5321.45)));
        $this->assertTrue($this->rule->matchesTariff(5321.4451, $this->order(5321.45)));
    }

    public function testOneCentMoreIsRejected(): void
    {
        $this->assertFalse($this->rule->matchesTariff(5321.46, $this->order(5321.45)));
    }

    public function testOneCentLessIsRejected(): void
    {
        $this->assertFalse($this->rule->matchesTariff(5321.44, $this->order(5321.45)));
    }

    public function testTariffIsBeforeDiscount(): void
    {
        $this->assertSame(5000.5, $this->rule->getTariffAmount($this->order(5000.5, 1000)));
    }

    /**
     * Partial shipping coupon (50 %): Hop quotes the same 5200 again, so the change is allowed
     * even though the buyer paid 2600.
     */
    public function testPartialDiscountComparesAgainstTariffBeforeDiscount(): void
    {
        $order = $this->order(5200, 2600);

        $this->assertTrue($this->rule->matchesTariff(5200, $order));
        $this->assertFalse($this->rule->matchesTariff(2600, $order));
        $this->assertFalse($this->rule->matchesTariff(5300, $order));
        $this->assertFalse($this->rule->isFreeShipping($order));
    }

    public function testFullShippingDiscountIsFreeShipping(): void
    {
        $this->assertTrue($this->rule->isFreeShipping($this->order(5000, 5000)));
    }

    public function testZeroShippingIsFreeShipping(): void
    {
        $this->assertTrue($this->rule->isFreeShipping($this->order(0, 0)));
    }

    public function testFreeshippingMethodIsFreeShipping(): void
    {
        $this->assertTrue($this->rule->isFreeShipping($this->order(100, 0, 'freeshipping_freeshipping')));
    }

    public function testPaidShippingIsNotFree(): void
    {
        $this->assertFalse($this->rule->isFreeShipping($this->order(5000, 4999.99)));
    }
}
