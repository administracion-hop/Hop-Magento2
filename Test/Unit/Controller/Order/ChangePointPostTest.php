<?php

namespace Hop\Envios\Test\Unit\Controller\Order;

use Hop\Envios\Controller\Order\ChangePointPost;
use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\ChangeService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangePointPostTest extends TestCase
{
    /** @var RequestInterface|MockObject */
    private $request;

    /** @var CustomerSession|MockObject */
    private $customerSession;

    /** @var OrderRepositoryInterface|MockObject */
    private $orderRepository;

    /** @var OrderViewAuthorizationInterface|MockObject */
    private $orderAuthorization;

    /** @var ChangeService|MockObject */
    private $changeService;

    /** @var array */
    private $sessionData = [];

    /** @var array|null */
    private $response;

    /** @var ChangePointPost */
    private $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')->willReturnMap([
            ['order_id', null, '100'],
            ['point_id', null, ' point-new '],
        ]);

        $this->customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId', 'getData'])
            // setData is resolved by SessionManager::__call() onto the session storage
            ->addMethods(['setData'])
            ->getMock();
        $this->customerSession->method('getCustomerId')->willReturn(7);
        $this->customerSession->method('getData')->willReturnCallback(fn ($key) => $this->sessionData[$key] ?? null);
        $this->customerSession->method('setData')->willReturnCallback(function ($key, $value) {
            $this->sessionData[$key] = $value;
            return $this->customerSession;
        });

        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderAuthorization = $this->createMock(OrderViewAuthorizationInterface::class);
        $this->changeService = $this->createMock(ChangeService::class);

        $json = $this->createMock(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->response = $data;
            return $json;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $this->controller = new ChangePointPost(
            $this->request,
            $this->customerSession,
            $this->orderRepository,
            $this->orderAuthorization,
            $this->changeService,
            $this->createMock(ChangeEligibility::class),
            $jsonFactory,
            $this->createMock(RedirectFactory::class),
            $this->createMock(ManagerInterface::class)
        );
    }

    /**
     * @return Order|MockObject
     */
    private function order()
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $order->method('getId')->willReturn(100);
        return $order;
    }

    public function testNotLoggedIn(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(false);
        $this->changeService->expects($this->never())->method('change');

        $this->controller->execute();

        $this->assertSame('login_required', $this->response['code']);
        $this->assertFalse($this->response['success']);
    }

    public function testSomeoneElsesOrderAnswersLikeANonexistentOne(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->changeService->expects($this->never())->method('change');

        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderAuthorization->method('canView')->willReturn(false);
        $this->controller->execute();
        $someoneElses = $this->response;

        $this->setUp();
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->orderRepository->method('get')->willThrowException(new NoSuchEntityException());
        $this->controller->execute();

        $this->assertSame(ChangeService::CODE_NOT_FOUND, $someoneElses['code']);
        $this->assertSame($someoneElses, $this->response);
    }

    public function testConfirmationIsDelegatedToTheService(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $order = $this->order();
        $this->orderRepository->method('get')->with(100)->willReturn($order);
        $this->orderAuthorization->method('canView')->willReturn(true);
        $this->changeService->expects($this->once())
            ->method('change')
            ->with($order, 'point-new', 7)
            ->willReturn(['code' => ChangeService::CODE_PRICE_MISMATCH, 'message' => 'otro costo']);

        $this->controller->execute();

        $this->assertSame(
            ['success' => false, 'code' => ChangeService::CODE_PRICE_MISMATCH, 'message' => 'otro costo'],
            $this->response
        );
    }

    public function testAttemptsAreCappedPerOrder(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderAuthorization->method('canView')->willReturn(true);
        $this->changeService->expects($this->exactly(ChangePointPost::MAX_ATTEMPTS))
            ->method('change')
            ->willReturn(['code' => ChangeService::CODE_PRICE_MISMATCH, 'message' => '']);

        for ($i = 0; $i < ChangePointPost::MAX_ATTEMPTS; $i++) {
            $this->controller->execute();
        }
        $this->controller->execute();

        $this->assertSame('too_many_attempts', $this->response['code']);
    }

    /**
     * @dataProvider unquotedCodes
     */
    public function testRejectionsBeforeQuotingDontSpendAttempts(string $code): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderAuthorization->method('canView')->willReturn(true);
        $this->changeService->expects($this->exactly(ChangePointPost::MAX_ATTEMPTS + 1))
            ->method('change')
            ->willReturn(['code' => $code, 'message' => '']);

        for ($i = 0; $i <= ChangePointPost::MAX_ATTEMPTS; $i++) {
            $this->controller->execute();
        }

        $this->assertSame($code, $this->response['code']);
    }

    public static function unquotedCodes(): array
    {
        return [
            [ChangeService::CODE_SAME_POINT],
            [ChangeService::CODE_INVALID_POINT],
            [ChangeService::CODE_DISPATCHED],
            [ChangeService::CODE_NOT_ELIGIBLE],
        ];
    }

    public function testQuoteErrorsSpendAttempts(): void
    {
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->order());
        $this->orderAuthorization->method('canView')->willReturn(true);
        $this->changeService->expects($this->exactly(ChangePointPost::MAX_ATTEMPTS))
            ->method('change')
            ->willReturn(['code' => ChangeService::CODE_QUOTE_ERROR, 'message' => '']);

        for ($i = 0; $i <= ChangePointPost::MAX_ATTEMPTS; $i++) {
            $this->controller->execute();
        }

        $this->assertSame('too_many_attempts', $this->response['code']);
    }
}
