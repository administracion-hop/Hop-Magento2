<?php

namespace Hop\Envios\Controller\Order;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\ChangeService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;
use Magento\Sales\Model\Order;

/**
 * Shared access rules for the buyer's "Cambiar punto de retiro" in My Orders: logged-in
 * customers only, and an order that doesn't exist answers the same as someone else's.
 *
 * Magento's OrderLoader isn't reused: on someone else's order it redirects to "* /* /history",
 * which under this route resolves to a page that doesn't exist.
 */
abstract class AbstractChangePoint
{
    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var OrderViewAuthorizationInterface
     */
    protected $orderAuthorization;

    /**
     * @var ChangeService
     */
    protected $changeService;

    /**
     * @var ChangeEligibility
     */
    protected $eligibility;

    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var RedirectFactory
     */
    protected $redirectFactory;

    /**
     * @var ManagerInterface
     */
    protected $messageManager;

    /**
     * @param RequestInterface $request
     * @param CustomerSession $customerSession
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderViewAuthorizationInterface $orderAuthorization
     * @param ChangeService $changeService
     * @param ChangeEligibility $eligibility
     * @param JsonFactory $jsonFactory
     * @param RedirectFactory $redirectFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        RequestInterface $request,
        CustomerSession $customerSession,
        OrderRepositoryInterface $orderRepository,
        OrderViewAuthorizationInterface $orderAuthorization,
        ChangeService $changeService,
        ChangeEligibility $eligibility,
        JsonFactory $jsonFactory,
        RedirectFactory $redirectFactory,
        ManagerInterface $messageManager
    ) {
        $this->request = $request;
        $this->customerSession = $customerSession;
        $this->orderRepository = $orderRepository;
        $this->orderAuthorization = $orderAuthorization;
        $this->changeService = $changeService;
        $this->eligibility = $eligibility;
        $this->jsonFactory = $jsonFactory;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * The order in the request if the logged-in customer can see it, else null — the same
     * for an order that doesn't exist and for someone else's.
     *
     * @return Order|null
     */
    protected function getOrder(): ?Order
    {
        $orderId = (int)$this->request->getParam('order_id');
        if (!$orderId) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        if (!$order instanceof Order || !$this->orderAuthorization->canView($order)) {
            return null;
        }
        return $order;
    }

    /**
     * @return int
     */
    protected function getCustomerId(): int
    {
        return (int)$this->customerSession->getCustomerId();
    }

    /**
     * @param array $data
     * @return \Magento\Framework\Controller\Result\Json
     */
    protected function json(array $data)
    {
        return $this->jsonFactory->create()->setData($data);
    }
}
