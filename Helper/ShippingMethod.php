<?php

namespace Hop\Envios\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Hop\Envios\Model\QuotePickupPointRepository;
use Hop\Envios\Model\OrderPickupPointRepository;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\Webservice;

class ShippingMethod extends AbstractHelper
{

    /**
     * @var CollectionFactoryexit
     */
    protected $_orderCollectionFactory;

    /**
     * @var OrderResourceModel
     */
    protected $_orderResourceModel;

    /**
     * @var QuotePickupPointRepository
     */
    protected $quotePickupPointRepository;

    /**
     * @var OrderPickupPointRepository
     */
    protected $orderPickupPointRepository;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var Webservice
     */
    protected $webservice;


    /**
     * @param Context $context
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderResourceModel $orderResourceModel
     * @param QuotePickupPointRepository $quotePickupPointRepository
     * @param OrderPickupPointRepository $orderPickupPointRepository
     * @param HopEnviosRepository $hopEnviosRepository
     * @param Webservice $webservice
     */
    public function __construct(
        Context $context,
        CollectionFactory $orderCollectionFactory,
        OrderResourceModel $orderResourceModel,
        QuotePickupPointRepository $quotePickupPointRepository,
        OrderPickupPointRepository $orderPickupPointRepository,
        HopEnviosRepository $hopEnviosRepository,
        Webservice $webservice
    ) {
        parent::__construct($context);
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_orderResourceModel = $orderResourceModel;
        $this->quotePickupPointRepository = $quotePickupPointRepository;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->webservice = $webservice;
    }

    /**
     * Get order
     *
     * @param int $orderId
     * @return Order
     */
    public function getOrder($orderId)
    {
        $collection = $this->_orderCollectionFactory->create();
        return $collection->addFieldToFilter('entity_id', ['eq' => $orderId])->getFirstItem();
    }

    /**
     * @param int $orderId
     * @param array $hopData
     * @return void
     */
    public function addHopData($orderId, $hopData)
    {
        $order = $this->getOrder($orderId);
        if ($order->getId()) {
            $pickupPointId = $hopData['hopPointId'];
            $shippingDescription = 'Retirá tu pedido en: ' .
                $hopData['hopPointReferenceName']
                . " ({$hopData['hopPointAddress']}) " .
                ' - Horario: ' . $hopData['hopPointSchedules'];
            $order->setShippingDescription($shippingDescription);
            $this->_orderResourceModel->save($order);
            $orderPickupPoint = $this->orderPickupPointRepository->getByOrderId((int)$order->getId());
            if (!$orderPickupPoint) {
                $orderPickupPoint = $this->orderPickupPointRepository->create();
                $orderPickupPoint->setOrderId((int)$order->getId());
                $orderPickupPoint->setOriginalPickupPointId($pickupPointId);
                $orderPickupPoint->setOriginalShippingDescription($shippingDescription);
                $orderPickupPoint->setOriginalZipCode($hopData['hopPointPostcode'] ?? '');
            }
            $orderPickupPoint->setPickupPointId($pickupPointId);
            $this->orderPickupPointRepository->save($orderPickupPoint);
        }
    }

    /**
     * Creates the hop_envios record for the order if it doesn't exist yet and, when the order
     * has no Hop shipment yet, dispatches it to Hop directly (no Magento shipment required).
     *
     * This is the "Hop-only" path used by the Send-to-Hop admin action and by
     * SalesOrderSaveAfter: merchants who want the Hop shipment created without generating a
     * Magento shipment. Orders that instead get a Magento shipment created (manually or via
     * the GenarateShipment cron) are dispatched by SalesOrderShipmentSaveAfter instead, which
     * also has the package/bulto data the multibulto API needs. Guarded by getInfoHop() so an
     * order already dispatched through either path isn't sent to Hop twice.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param bool $dispatchNow Whether to call the Hop API right away. The Send-to-Hop button
     *        passes true: the admin explicitly asked to dispatch, and no Magento shipment is
     *        involved. SalesOrderSaveAfter passes false: it only records the order as pending so
     *        GenarateShipment creates the Magento shipment, and SalesOrderShipmentSaveAfter — the
     *        only path with the per-bulto data — does the dispatch. Dispatching here on a status
     *        change would race that flow: Magento moves an order to processing as soon as the
     *        first partial shipment is saved, so Hop would get an envío for the whole order while
     *        the multibulto flow was still waiting for the remaining packages.
     * @return bool
     */
    public function createShipmentData($order, $dispatchNow = true)
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($order->getId());

        if (!$hopEnvios) {
            $hopEnvios = $this->hopEnviosRepository->create();
            $hopEnvios->setOrderId($order->getId());
            $hopEnvios->setIncrementId($order->getIncrementId());
            $this->hopEnviosRepository->save($hopEnvios);
        }

        if ($dispatchNow && !$hopEnvios->getInfoHop()) {
            $this->webservice->setStoreId($order->getStoreId());
            $result = $this->webservice->createShipping($order);

            if (is_string($result) && $result !== '') {
                $hopEnvios->setInfoHop($result);
                $hopEnvios->setStatusShipment('completed');
                $this->hopEnviosRepository->save($hopEnvios);
            } else {
                $error = (is_array($result) && isset($result['error']))
                    ? $result['error']
                    : __('No se pudo generar el envío en Hop.');
                $order->setShippingDescription($error);
                $order->getResource()->saveAttribute($order, 'shipping_description');
                return false;
            }
        }

        return true;
    }
}
