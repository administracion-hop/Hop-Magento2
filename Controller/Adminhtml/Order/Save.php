<?php

namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Backend\App\Action;
use Magento\Framework\App\Request\Http;
use Magento\Framework\UrlInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
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
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var UrlInterface
     */
    protected $_backendUrl;

    /**
     * @param Http $request
     * @param RedirectFactory $resultRedirectFactory
     * @param UrlInterface $urlInterface
     * @param ShippingMethod $shippingMethodHelper
     * @param Context $context
     */
    public function __construct(
        Http $request,
        RedirectFactory $resultRedirectFactory,
        UrlInterface $urlInterface,
        ShippingMethod $shippingMethodHelper,
        Context $context
    ) {
        $this->_request = $request;
        $this->resultRedirectFactory = $resultRedirectFactory;
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
        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setUrl($orderUrl);
        $this->messageManager->addSuccessMessage(__('Nuevo punto HOP seleccionado.'));
        return $resultRedirect;
    }
}
