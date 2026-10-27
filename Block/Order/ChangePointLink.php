<?php

namespace Hop\Envios\Block\Order;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Model\Order;

/**
 * "Cambiar punto de retiro" on the order page in My Orders, shown only while the buyer can
 * still change the pickup point.
 */
class ChangePointLink extends Template
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var ChangeEligibility
     */
    protected $eligibility;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param CustomerSession $customerSession
     * @param ChangeEligibility $eligibility
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        CustomerSession $customerSession,
        ChangeEligibility $eligibility,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->customerSession = $customerSession;
        $this->eligibility = $eligibility;
        parent::__construct($context, $data);
    }

    /**
     * The order Magento's own order page loaded (Magento\Sales\Controller\AbstractController\OrderLoader).
     *
     * @return Order|null
     */
    public function getOrder()
    {
        return $this->registry->registry('current_order');
    }

    /**
     * @return bool
     */
    public function canChange(): bool
    {
        $order = $this->getOrder();
        return $order instanceof Order
            && $this->customerSession->isLoggedIn()
            && $this->eligibility->canChange($order, (int)$this->customerSession->getCustomerId());
    }

    /**
     * @return string
     */
    public function getChangeUrl(): string
    {
        return $this->getUrl('hop/order/changepoint', ['order_id' => $this->getOrder()->getId()]);
    }

    /**
     * @return string
     */
    protected function _toHtml()
    {
        return $this->canChange() ? parent::_toHtml() : '';
    }
}
