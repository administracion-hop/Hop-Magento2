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
     * @param Magento\Sales\Model\Order $order
     * @return bool
     */
    public function createShipmentData($order)
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($order->getId());

        if (!$hopEnvios) {
            $hopEnvios = $this->hopEnviosRepository->create();
            $hopEnvios->setOrderId($order->getId());
            $hopEnvios->setIncrementId($order->getIncrementId());
            $this->hopEnviosRepository->save($hopEnvios);
        }

        if(!$hopEnvios->getInfoHop()) {
            $result = $this->webservice->createShipping($order);
            if(!isset($result['error'])){
                $hopEnvios->setInfoHop($result);
                $this->hopEnviosRepository->save($hopEnvios);
            } else {
                $order->setShippingDescription($result['error']);
                $order->getResource()->saveAttribute($order, "shipping_description");
                return false;
            }
        }
        return true;
    }
}
