<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\DispatchStatus;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;

class ChangeEligibilityTest extends TestCase
{
    private const CUSTOMER_ID = 7;

    /**
     * @var DispatchStatus|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dispatchStatus;

    /**
     * @var ChangeEligibility
     */
    private $eligibility;

    protected function setUp(): void
    {
        $this->dispatchStatus = $this->createMock(DispatchStatus::class);
        $this->eligibility = new ChangeEligibility($this->dispatchStatus);
    }

    /**
     * @param array $data
     * @return Order
     */
    private function order(array $data = []): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getCustomerId', 'getShippingMethod', 'getState'])
            ->getMock();
        $data += [
            'id' => 100,
            'customer_id' => self::CUSTOMER_ID,
            'shipping_method' => 'hop_hop',
            'state' => Order::STATE_PROCESSING,
        ];
        $order->method('getId')->willReturn($data['id']);
        $order->method('getCustomerId')->willReturn($data['customer_id']);
        $order->method('getShippingMethod')->willReturn($data['shipping_method']);
        $order->method('getState')->willReturn($data['state']);
        return $order;
    }

    public function testEligibleOrder(): void
    {
        $this->dispatchStatus->method('isDispatched')->with(100)->willReturn(false);

        $this->assertSame(ChangeEligibility::OK, $this->eligibility->check($this->order(), self::CUSTOMER_ID));
        $this->assertTrue($this->eligibility->canChange($this->order(), self::CUSTOMER_ID));
    }

    public function testOrderOfAnotherCustomer(): void
    {
        $this->assertSame(
            ChangeEligibility::NOT_OWNER,
            $this->eligibility->check($this->order(['customer_id' => 99]), self::CUSTOMER_ID)
        );
    }

    public function testGuestOrder(): void
    {
        $this->assertSame(
            ChangeEligibility::NOT_OWNER,
            $this->eligibility->check($this->order(['customer_id' => null]), self::CUSTOMER_ID)
        );
    }

    public function testNotLoggedIn(): void
    {
        $this->assertSame(ChangeEligibility::NOT_OWNER, $this->eligibility->check($this->order(), 0));
    }

    public function testNonexistentOrder(): void
    {
        $this->assertSame(
            ChangeEligibility::NOT_OWNER,
            $this->eligibility->check($this->order(['id' => null, 'customer_id' => null]), self::CUSTOMER_ID)
        );
    }

    public function testOtherShippingMethod(): void
    {
        $this->assertSame(
            ChangeEligibility::NOT_HOP,
            $this->eligibility->check($this->order(['shipping_method' => 'flatrate_flatrate']), self::CUSTOMER_ID)
        );
    }

    /**
     * @dataProvider closedStates
     */
    public function testCanceledOrClosedOrder(string $state): void
    {
        $this->assertSame(
            ChangeEligibility::CLOSED_STATE,
            $this->eligibility->check($this->order(['state' => $state]), self::CUSTOMER_ID)
        );
    }

    public static function closedStates(): array
    {
        return [[Order::STATE_CANCELED], [Order::STATE_CLOSED]];
    }

    /**
     * @dataProvider openStates
     */
    public function testHoldedAndPaymentReviewAreAllowed(string $state): void
    {
        $this->dispatchStatus->method('isDispatched')->willReturn(false);

        $this->assertSame(
            ChangeEligibility::OK,
            $this->eligibility->check($this->order(['state' => $state]), self::CUSTOMER_ID)
        );
    }

    public static function openStates(): array
    {
        return [[Order::STATE_HOLDED], [Order::STATE_PAYMENT_REVIEW], [Order::STATE_NEW]];
    }

    public function testDispatchedOrder(): void
    {
        $this->dispatchStatus->method('isDispatched')->willReturn(true);

        $this->assertSame(ChangeEligibility::DISPATCHED, $this->eligibility->check($this->order(), self::CUSTOMER_ID));
    }
}
