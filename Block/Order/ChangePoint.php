<?php

namespace Hop\Envios\Block\Order;

use Hop\Envios\Helper\Data as HopHelper;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Pickup point change page in My Orders. The controller already checked the customer can
 * change this order's point before rendering it.
 */
class ChangePoint extends Template
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var HopHelper
     */
    protected $helper;

    /**
     * @var FormKey
     */
    protected $formKey;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var OrderInterface|null
     */
    private $order;

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param HopHelper $helper
     * @param FormKey $formKey
     * @param Json $json
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        HopHelper $helper,
        FormKey $formKey,
        Json $json,
        array $data = []
    ) {
        $this->orderRepository = $orderRepository;
        $this->helper = $helper;
        $this->formKey = $formKey;
        $this->json = $json;
        parent::__construct($context, $data);
    }

    /**
     * @return OrderInterface|null
     */
    public function getOrder()
    {
        if ($this->order === null) {
            try {
                $this->order = $this->orderRepository->get((int)$this->getRequest()->getParam('order_id'));
            } catch (NoSuchEntityException $e) {
                return null;
            }
        }
        return $this->order;
    }

    /**
     * @return string
     */
    public function getOrderViewUrl(): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $this->getOrder()->getEntityId()]);
    }

    /**
     * @return string
     */
    public function getJsConfig(): string
    {
        $order = $this->getOrder();
        $orderId = (int)$order->getEntityId();

        return $this->json->serialize([
            'orderId' => $orderId,
            'pointsUrl' => $this->getUrl('hop/order/points', ['order_id' => $orderId]),
            'saveUrl' => $this->getUrl('hop/order/changepointpost'),
            'orderViewUrl' => $this->getOrderViewUrl(),
            'formKey' => $this->formKey->getFormKey(),
            'apiKey' => (string)$this->helper->getApiKey($order->getStoreId()),
            'iconUrl' => $this->getViewFileUrl('Hop_Envios::images/hop_marker.png'),
        ]);
    }
}
