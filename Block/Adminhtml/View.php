<?php

namespace Hop\Envios\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Template;
use Magento\Sales\Model\Order;
use Magento\Framework\UrlInterface;

class View extends Template
{
    public $_template = 'Hop_Envios::view.phtml';

    /**
     * @var Order
     */
    protected $order;

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @param Order $order
     * @param UrlInterface $backendUrl
     * @param Context $context
     */
    public function __construct(
        Order $order,
        UrlInterface $backendUrl,
        Context $context
    ) {
        $this->backendUrl = $backendUrl;
        $this->order = $order;
        parent::__construct($context);
    }

    /**
     * @return string
     */
    public function getZipcode()
    {
        $orderId = $this->getData('order_id');
        $order = $this->order->load($orderId);
        $shippingAddress = $order->getShippingAddress();
        return $shippingAddress->getPostcode();
    }

    /**
     * @return string
     */
    public function getFormAction()
    {
        return $this->backendUrl->getUrl('hop/order/save');
    }
}
