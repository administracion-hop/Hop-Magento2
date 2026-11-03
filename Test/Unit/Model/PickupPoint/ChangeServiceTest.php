<?php

namespace Hop\Envios\Test\Unit\Model\PickupPoint;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Model\OrderPickupPointRepository;
use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\ChangePriceRule;
use Hop\Envios\Model\PickupPoint\ChangeService;
use Hop\Envios\Model\PickupPoint\DispatchStatus;
use Hop\Envios\Model\PickupPoint\ScheduleFormatter;
use Hop\Envios\Model\Shipping\HopPriceCalculator;
use Hop\Envios\Model\Shipping\OrderPackageBuilder;
use Hop\Envios\Model\Webservice;
use Magento\Framework\DataObject;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeServiceTest extends TestCase
{
    private const CUSTOMER_ID = 7;
    private const ORDER_ID = 100;
    private const CURRENT_POINT = 'point-current';
    private const NEW_POINT = 'point-new';

    /** @var ChangeEligibility|MockObject */
    private $eligibility;

    /** @var DispatchStatus|MockObject */
    private $dispatchStatus;

    /** @var OrderPickupPointRepository|MockObject */
    private $orderPickupPointRepository;

    /** @var OrderPackageBuilder|MockObject */
    private $packageBuilder;

    /** @var HopPriceCalculator|MockObject */
    private $priceCalculator;

    /** @var ChangePriceRule|MockObject */
    private $priceRule;

    /** @var Webservice|MockObject */
    private $webservice;

    /** @var ShippingMethod|MockObject */
    private $shippingMethod;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var Order|MockObject */
    private $order;

    /** @var ChangeService */
    private $service;

    protected function setUp(): void
    {
        $this->eligibility = $this->createMock(ChangeEligibility::class);
        $this->eligibility->method('check')->willReturn(ChangeEligibility::OK);
        $this->dispatchStatus = $this->createMock(DispatchStatus::class);
        $this->orderPickupPointRepository = $this->createMock(OrderPickupPointRepository::class);
        $this->orderPickupPointRepository->method('getByOrderId')
            ->willReturn(new DataObject(['pickup_point_id' => self::CURRENT_POINT]));
        $this->packageBuilder = $this->createMock(OrderPackageBuilder::class);
        $this->packageBuilder->method('getDestZipCode')->willReturn('1414');
        $this->packageBuilder->method('getCountryCode')->willReturn('AR');
        $this->packageBuilder->method('build')->willReturn(['weight' => 300]);
        $this->priceCalculator = $this->createMock(HopPriceCalculator::class);
        $this->priceRule = $this->createMock(ChangePriceRule::class);
        $this->webservice = $this->createMock(Webservice::class);
        $this->webservice->method('getPickupPoints')->willReturn(json_decode(json_encode(['data' => [
            $this->point(self::CURRENT_POINT, 'Punto actual'),
            $this->point(self::NEW_POINT, 'Punto nuevo'),
        ]])));
        $this->shippingMethod = $this->createMock(ShippingMethod::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getShippingDescription', 'getIncrementId'])
            ->getMock();
        $this->order->method('getId')->willReturn(self::ORDER_ID);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getShippingDescription')->willReturn('Retirá tu pedido en: Punto actual');

        $this->service = new ChangeService(
            $this->eligibility,
            $this->dispatchStatus,
            $this->orderPickupPointRepository,
            $this->packageBuilder,
            $this->priceCalculator,
            $this->priceRule,
            $this->webservice,
            $this->shippingMethod,
            new ScheduleFormatter(),
            $this->orderRepository,
            $this->createMock(HopHelper::class)
        );
    }

    /**
     * @param string $id
     * @param string $name
     * @return array
     */
    private function point(string $id, string $name): array
    {
        return [
            'id' => $id,
            'reference_name' => $name,
            'name' => $name . ' SRL',
            'full_address' => 'Bonpland 715',
            'zip_code' => '1414',
            'provider_code' => '1804',
            'distributor_id' => 'dist',
            'seller' => null,
            'schedules' => [
                ['day_code' => '1', 'day_description' => 'Lunes', 'hour_from' => '08:00:00', 'hour_to' => '18:00:00'],
            ],
        ];
    }

    /**
     * The history comment is added on a fresh copy of the order.
     *
     * @return Order|MockObject
     */
    private function expectSuccessfulSave()
    {
        $freshOrder = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addCommentToStatusHistory', 'getShippingDescription'])
            ->getMock();
        $freshOrder->method('getShippingDescription')->willReturn('Retirá tu pedido en: Punto nuevo');
        $this->shippingMethod->method('getOrder')->with(self::ORDER_ID)->willReturn($freshOrder);
        $this->orderRepository->expects($this->once())->method('save')->with($freshOrder);
        return $freshOrder;
    }

    private function expectNothingSaved(): void
    {
        $this->shippingMethod->expects($this->never())->method('addHopData');
        $this->orderRepository->expects($this->never())->method('save');
    }

    public function testChangeWithSamePriceIsSavedWithServerSideData(): void
    {
        $this->priceRule->method('isFreeShipping')->willReturn(false);
        $this->priceCalculator->expects($this->once())
            ->method('quote')
            ->with(['weight' => 300], '1414', self::NEW_POINT, 1)
            ->willReturn(5198.96);
        $this->priceRule->method('matchesChargedAmount')->with(5198.96)->willReturn(true);
        $freshOrder = $this->expectSuccessfulSave();

        $this->shippingMethod->expects($this->once())
            ->method('addHopData')
            ->with(self::ORDER_ID, $this->callback(function ($hopData) {
                return $hopData['hopPointId'] === self::NEW_POINT
                    && $hopData['hopPointReferenceName'] === 'Punto nuevo'
                    && $hopData['hopPointAddress'] === 'Bonpland 715'
                    && $hopData['hopPointSchedules'] === 'Lunes, 08:00 a 18:00'
                    && $hopData['hopPointPostcode'] === '1414';
            }));
        $freshOrder->expects($this->once())
            ->method('addCommentToStatusHistory')
            ->with(
                $this->callback(function ($comment) {
                    $text = (string)$comment;
                    return strpos($text, 'cambiado por el comprador') !== false
                        && strpos($text, 'Punto actual') !== false
                        && strpos($text, self::CURRENT_POINT) !== false
                        && strpos($text, 'Punto nuevo') !== false
                        && strpos($text, self::NEW_POINT) !== false;
                }),
                false,
                false
            );

        $this->assertSame(ChangeService::CODE_OK, $this->service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID)['code']);
    }

    public function testDifferentPriceIsRejectedAndNothingChanges(): void
    {
        $this->priceRule->method('isFreeShipping')->willReturn(false);
        $this->priceCalculator->expects($this->once())->method('quote')->willReturn(6000.0);
        $this->priceRule->method('matchesChargedAmount')->willReturn(false);
        $this->expectNothingSaved();

        $result = $this->service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID);

        $this->assertSame(ChangeService::CODE_PRICE_MISMATCH, $result['code']);
        $this->assertStringContainsString('otro costo de envío', $result['message']);
    }

    public function testQuoteErrorIsRejectedAndNothingChanges(): void
    {
        $this->priceRule->method('isFreeShipping')->willReturn(false);
        $this->priceCalculator->method('quote')->willReturn(null);
        $this->expectNothingSaved();

        $this->assertSame(ChangeService::CODE_QUOTE_ERROR, $this->service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID)['code']);
    }

    public function testFreeShippingAcceptsAnyPointWithoutQuoting(): void
    {
        $this->priceRule->method('isFreeShipping')->willReturn(true);
        $this->priceCalculator->expects($this->never())->method('quote');
        $this->shippingMethod->expects($this->once())->method('addHopData');
        $this->expectSuccessfulSave();

        $this->assertSame(ChangeService::CODE_OK, $this->service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID)['code']);
    }

    public function testSamePointIsRejectedWithoutQuoting(): void
    {
        $this->priceCalculator->expects($this->never())->method('quote');
        $this->expectNothingSaved();

        $this->assertSame(ChangeService::CODE_SAME_POINT, $this->service->change($this->order, self::CURRENT_POINT, self::CUSTOMER_ID)['code']);
    }

    public function testPointNotOfferedByHopIsRejected(): void
    {
        $this->priceCalculator->expects($this->never())->method('quote');
        $this->expectNothingSaved();

        $this->assertSame(ChangeService::CODE_INVALID_POINT, $this->service->change($this->order, 'injected-point', self::CUSTOMER_ID)['code']);
    }

    public function testEmptyPointIsRejected(): void
    {
        $this->expectNothingSaved();

        $this->assertSame(ChangeService::CODE_INVALID_POINT, $this->service->change($this->order, '', self::CUSTOMER_ID)['code']);
    }

    /**
     * @dataProvider eligibilityResults
     */
    public function testIneligibleOrdersAreRejectedBeforeAnythingElse(string $eligibility, string $expectedCode): void
    {
        $eligibilityMock = $this->createMock(ChangeEligibility::class);
        $eligibilityMock->method('check')->willReturn($eligibility);
        $this->webservice->expects($this->never())->method('getPickupPoints');
        $this->expectNothingSaved();

        $service = new ChangeService(
            $eligibilityMock,
            $this->dispatchStatus,
            $this->orderPickupPointRepository,
            $this->packageBuilder,
            $this->priceCalculator,
            $this->priceRule,
            $this->webservice,
            $this->shippingMethod,
            new ScheduleFormatter(),
            $this->orderRepository,
            $this->createMock(HopHelper::class)
        );

        $this->assertSame($expectedCode, $service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID)['code']);
    }

    public static function eligibilityResults(): array
    {
        return [
            'pedido ajeno igual a inexistente' => [ChangeEligibility::NOT_OWNER, ChangeService::CODE_NOT_FOUND],
            'otro método de envío' => [ChangeEligibility::NOT_HOP, ChangeService::CODE_NOT_ELIGIBLE],
            'cancelado o cerrado' => [ChangeEligibility::CLOSED_STATE, ChangeService::CODE_NOT_ELIGIBLE],
            'ya despachado' => [ChangeEligibility::DISPATCHED, ChangeService::CODE_DISPATCHED],
        ];
    }

    public function testDispatchedWhileChoosingIsRejected(): void
    {
        $this->priceRule->method('isFreeShipping')->willReturn(false);
        $this->priceCalculator->method('quote')->willReturn(5198.96);
        $this->priceRule->method('matchesChargedAmount')->willReturn(true);
        $this->dispatchStatus->method('hasDispatchStarted')->with(self::ORDER_ID)->willReturn(true);
        $this->expectNothingSaved();

        $this->assertSame(ChangeService::CODE_DISPATCHED, $this->service->change($this->order, self::NEW_POINT, self::CUSTOMER_ID)['code']);
    }

    public function testCurrentPointId(): void
    {
        $this->assertSame(self::CURRENT_POINT, $this->service->getCurrentPointId($this->order));
    }

    public function testPointsAreListedForTheOrderDestinationAndStore(): void
    {
        $this->webservice->expects($this->once())->method('setStoreId')->with(1);

        $points = $this->service->getPoints($this->order);

        $this->assertCount(2, $points);
        $this->assertSame(self::NEW_POINT, $points[1]['id']);
    }
}
