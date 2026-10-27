<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\PickupPoint\ChangePriceRule;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class ChangePriceRuleTest extends TestCase
{
    /**
     * @var ChangePriceRule
     */
    private $rule;

    protected function setUp(): void
    {
        $this->rule = new ChangePriceRule();
    }

    /**
     * @param float|null $shipping
     * @param float|null $discount
     * @param string $method
     * @return Order
     */
    private function order($shipping, $discount = null, string $method = 'hop_hop'): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBaseShippingAmount', 'getBaseShippingDiscountAmount', 'getShippingMethod'])
            ->getMock();
        $order->method('getBaseShippingAmount')->willReturn($shipping);
        $order->method('getBaseShippingDiscountAmount')->willReturn($discount);
        $order->method('getShippingMethod')->willReturn($method);
        return $order;
    }

    public function testChargedAmountIsNetOfDiscount(): void
    {
        $this->assertSame(4000.5, $this->rule->getChargedAmount($this->order(5000.5, 1000)));
    }

    public function testEqualQuoteMatches(): void
    {
        $this->assertTrue($this->rule->matchesChargedAmount(5321.45, $this->order(5321.45)));
    }

    public function testQuoteIsRoundedToTwoDecimals(): void
    {
        $this->assertTrue($this->rule->matchesChargedAmount(5321.4549, $this->order(5321.45)));
        $this->assertTrue($this->rule->matchesChargedAmount(5321.4451, $this->order(5321.45)));
    }

    public function testOneCentMoreIsRejected(): void
    {
        $this->assertFalse($this->rule->matchesChargedAmount(5321.46, $this->order(5321.45)));
    }

    public function testOneCentLessIsRejected(): void
    {
        $this->assertFalse($this->rule->matchesChargedAmount(5321.44, $this->order(5321.45)));
    }

    public function testQuoteIsComparedAgainstChargedAfterDiscount(): void
    {
        $order = $this->order(5000, 500);

        $this->assertTrue($this->rule->matchesChargedAmount(4500, $order));
        $this->assertFalse($this->rule->matchesChargedAmount(5000, $order));
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
