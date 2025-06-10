<?php

namespace Hop\Envios\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Magento\Framework\App\Helper\Context;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Hop\Envios\Model\SelectedPickupPointRepository;

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
     * @var SelectedPickupPointRepository
     */
    protected $selectedPickupPointRepository;

    /**
     * @param Context $context
     * @param CollectionFactory $orderCollectionFactory
     * @param OrderResourceModel $orderResourceModel
     * @param SelectedPickupPointRepository $selectedPickupPointRepository
     */
    public function __construct(
        Context $context,
        CollectionFactory $orderCollectionFactory,
        OrderResourceModel $orderResourceModel,
        SelectedPickupPointRepository $selectedPickupPointRepository
    ) {
        parent::__construct($context);
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_orderResourceModel = $orderResourceModel;
        $this->selectedPickupPointRepository = $selectedPickupPointRepository;
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
            $selectedPickupPoint = $this->selectedPickupPointRepository->getByQuoteId($order->getQuoteId());
            if (!$selectedPickupPoint) {
                $selectedPickupPoint = $this->selectedPickupPointRepository->create();
                $selectedPickupPoint->setQuoteId($order->getQuoteId());
                $selectedPickupPoint->setOriginalPickupPointId($pickupPointId);
                $selectedPickupPoint->setOriginalShippingDescription($shippingDescription);
            }
            $selectedPickupPoint->setPickupPointId($pickupPointId);
            $this->selectedPickupPointRepository->save($selectedPickupPoint);
        }
    }
}
