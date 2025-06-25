<?php

namespace Hop\Envios\Block\Adminhtml\Order;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Hop\Envios\Helper\Data;

class HopSelectorView extends Template
{
    public $_template = 'Hop_Envios::order/select-view.phtml';

    /**
     * @var OrderInterface
     */
    protected $order;

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var Data
     */
    protected $helper;

    /**
     * @param OrderInterface $order
     * @param UrlInterface $backendUrl
     * @param OrderRepositoryInterface $orderRepository
     * @param Data $helper
     * @param Context $context
     */
    public function __construct(
        OrderInterface $order,
        UrlInterface $backendUrl,
        OrderRepositoryInterface $orderRepository,
        Data $helper,
        Context $context
    ) {
        $this->backendUrl = $backendUrl;
        $this->orderRepository = $orderRepository;
        $this->order = $order;
        $this->helper = $helper;
        parent::__construct($context);
    }

    /**
     * @return string
     */
    public function getZipcode()
    {
        $order = $this->getOrderById($this->getData('order_id'));
        $shippingAddress = $order->getShippingAddress();
        return $shippingAddress->getPostcode();
    }

    /**
     * @return string
     */
    public function getWarning()
    {
        $order = $this->getOrderById($this->getData('order_id'));
        $statuses = $this->helper->getStatusOrderAllowed();

        $orderStatus = $order->getStatus();

        if (in_array($orderStatus, $statuses)) {
            return __('Esta acción va a crear un envío en la plataforma de Hop, acorde a las configuraciones del método de envío.');
        }
        return __('Esta acción no va a crear un envío en la plataforma de Hop, acorde a las configuraciones del método de envío.');
    }

    /**
     * @return string
     */
    public function getFormAction()
    {
        return $this->backendUrl->getUrl('hop/order/save');
    }

    /**
     * Get order from ID
     *
     * @param int $orderId
     * @return \Magento\Sales\Api\Data\OrderInterface
     * @throws NoSuchEntityException
     */
    public function getOrderById($orderId)
    {
        if ($this->order->getEntityId()) {
            return $this->order;
        }
        $order = $this->orderRepository->get($orderId);
        if (!$order->getEntityId()) {
            throw new NoSuchEntityException(
                new Phrase(__('No such order with ID %1', $orderId))
            );
        }
        $this->order = $order;
        return $this->order;
    }
}
