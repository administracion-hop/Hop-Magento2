<?php

namespace Hop\Envios\Controller\Order;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\ChangeService;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Controller\AbstractController\OrderViewAuthorizationInterface;

/**
 * GET hop/order/changepoint/order_id/N — the page where the buyer picks a new pickup point.
 */
class ChangePoint extends AbstractChangePoint implements HttpGetActionInterface
{
    /**
     * @var PageFactory
     */
    protected $pageFactory;

    /**
     * @var CustomerUrl
     */
    protected $customerUrl;

    /**
     * @var UrlInterface
     */
    protected $url;

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
     * @param PageFactory $pageFactory
     * @param CustomerUrl $customerUrl
     * @param UrlInterface $url
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
        ManagerInterface $messageManager,
        PageFactory $pageFactory,
        CustomerUrl $customerUrl,
        UrlInterface $url
    ) {
        parent::__construct(
            $request,
            $customerSession,
            $orderRepository,
            $orderAuthorization,
            $changeService,
            $eligibility,
            $jsonFactory,
            $redirectFactory,
            $messageManager
        );
        $this->pageFactory = $pageFactory;
        $this->customerUrl = $customerUrl;
        $this->url = $url;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            $this->customerSession->setBeforeAuthUrl($this->url->getCurrentUrl());
            return $this->redirectFactory->create()->setUrl($this->customerUrl->getLoginUrl());
        }

        $order = $this->getOrder();
        if (!$order) {
            $this->messageManager->addErrorMessage(__('No encontramos el pedido.'));
            return $this->redirectFactory->create()->setPath('sales/order/history');
        }

        $eligibility = $this->eligibility->check($order, $this->getCustomerId());
        if ($eligibility !== ChangeEligibility::OK) {
            $this->messageManager->addErrorMessage(
                $eligibility === ChangeEligibility::DISPATCHED
                    ? __('Tu pedido ya fue despachado y no se puede cambiar el punto de retiro.')
                    : __('Este pedido no permite cambiar el punto de retiro.')
            );
            return $this->redirectFactory->create()->setPath('sales/order/view', ['order_id' => $order->getId()]);
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Cambiar punto de retiro'));
        /** @var \Magento\Customer\Block\Account\Navigation|false $navigation */
        $navigation = $page->getLayout()->getBlock('customer_account_navigation');
        if ($navigation) {
            $navigation->setActive('sales/order/history');
        }
        return $page;
    }
}
