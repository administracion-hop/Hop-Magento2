<?php

namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\App\Action;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\Controller\ResultFactory;
use Hop\Envios\Helper\ShippingMethod;

class Save extends Action
{
    /**
     * @var Http
     */
    protected $_request;

    /**
     * @var ShippingMethod
     */
    protected $shippingMethodHelper;

    /**
     * @var ResultFactory
     */
    protected $resultFactory;

    /**
     * @var UrlInterface
     */
    protected $_backendUrl;

    /**
     * @param Http $request,  
     * @param ResultFactory $resultFactory, 
     * @param UrlInterface $urlInterface,
     * @param ShippingMethod $shippingMethodHelper,
     * @param Context $context
     */
    public function __construct(
        Http $request,
        ResultFactory $resultFactory,
        UrlInterface $urlInterface,
        ShippingMethod $shippingMethodHelper,
        Context $context
    ) {
        $this->_request = $request;
        $this->resultFactory = $resultFactory;
        $this->_backendUrl = $urlInterface;
        $this->shippingMethodHelper = $shippingMethodHelper;
        parent::__construct($context);
    }

    /**
     * @return ResultFactory
     */
    public function execute()
    {
        $request = $this->getRequest();
        $orderId = $request->getParam('order_id');
        $params = $this->_request->getParams();
        unset($params['order_id']);
        $this->shippingMethodHelper->addHopData($orderId, $params);
        $orderUrl = $this->_backendUrl->getUrl('sales/order/view', ['order_id' => $orderId]);
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath($orderUrl);
        return $redirect;
    }
}
