<?php

namespace Hop\Envios\Block\Adminhtml\Order;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Template;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
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
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @param OrderInterface $order
     * @param UrlInterface $backendUrl
     * @param OrderRepositoryInterface $orderRepository
     * @param Data $helper
     * @param StoreManagerInterface $storeManager
     * @param Context $context
     */
    public function __construct(
        OrderInterface $order,
        UrlInterface $backendUrl,
        OrderRepositoryInterface $orderRepository,
        Data $helper,
        StoreManagerInterface $storeManager,
        Context $context
    ) {
        $this->backendUrl = $backendUrl;
        $this->orderRepository = $orderRepository;
        $this->order = $order;
        $this->helper = $helper;
        $this->storeManager = $storeManager;
        parent::__construct($context);
    }

    /**
     * @return string
     */
    public function getZipcode(): string
    {
        $order = $this->getOrderById($this->getData('order_id'));
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress) {
            return '';
        }
        return $shippingAddress->getPostcode() ?? '';
    }

    /**
     * @return string
     */
    public function getCountryCode(): string
    {
        $order = $this->getOrderById($this->getData('order_id'));
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress) {
            return $this->helper->getStoreCountry() ?: 'AR';
        }
        return $shippingAddress->getCountryId() ?: ($this->helper->getStoreCountry() ?: 'AR');
    }

    /**
     * The code actually sent to the pickup-points search: the resolved Ubigeo when Peru
     * ubigeo is configured for this order's store (either mode - "field" reads the
     * configured attribute, "mapping" combines region + the persisted
     * hop_ubigeo_provincia/hop_ubigeo_distrito order-address columns, see
     * Hop\Envios\Plugin\Quote\Address\UbigeoToOrderAddressPlugin), otherwise the plain
     * postcode. Unlike checkout's live-typed flow (PickupPointManagement::get()'s
     * $regionId/$provincia/$distrito params), the order's shipping address is already fully
     * persisted, so there is nothing left to resolve client-side here.
     *
     * @return string
     */
    public function getEffectiveZipCode(): string
    {
        $order = $this->getOrderById($this->getData('order_id'));
        $shippingAddress = $order->getShippingAddress();
        $storeId = $order->getStoreId();

        if ($shippingAddress && $this->helper->isUbigeoConfigured($storeId)) {
            $ubigeo = $this->helper->getUbigeoFromAddress($shippingAddress, $storeId);
            if ($ubigeo) {
                return (string)$ubigeo;
            }
        }

        return $this->getZipcode();
    }

    /**
     * The store-code segment is required: a REST call without it ("/rest/V2/...") always
     * resolves against the default store/website, regardless of which store the order
     * actually belongs to. Same fix as Hop_Envios/js/view/hop.js.
     *
     * @return string
     */
    public function getPointsUrl(): string
    {
        $effectiveZipCode = $this->getEffectiveZipCode();
        if ($effectiveZipCode === '') {
            return '';
        }

        $order = $this->getOrderById($this->getData('order_id'));
        try {
            $storeCode = $this->storeManager->getStore($order->getStoreId())->getCode();
        } catch (NoSuchEntityException $e) {
            $storeCode = $this->storeManager->getStore()->getCode();
        }

        return '/rest/' . rawurlencode($storeCode) . '/V2/hop-envios/points/'
            . rawurlencode($effectiveZipCode) . '/' . rawurlencode($this->getCountryCode());
    }

    /**
     * @return Phrase
     */
    public function getWarning(): Phrase
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
    public function getFormAction(): string
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
    public function getOrderById(int $orderId): OrderInterface
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
