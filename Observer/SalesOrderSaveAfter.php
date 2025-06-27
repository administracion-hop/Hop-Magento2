<?php

namespace Hop\Envios\Observer;

use Hop\Envios\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Hop\Envios\Helper\ShippingMethod;

/**
 * Class SalesOrderSaveAfter
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Observer
 */
class SalesOrderSaveAfter implements ObserverInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var ShippingMethod
     */
    protected $shippingMethodHelper;

    /**
     * SalesOrderSaveAfter constructor.
     * @param Data $data
     * @param ShippingMethod $shippingMethodHelper
     */
    public function __construct(
        Data $data,
        ShippingMethod $shippingMethodHelper
    ) {
        $this->helper = $data;
        $this->shippingMethodHelper = $shippingMethodHelper;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {

        try {
            if ($this->helper->isActive()) {
                $order = $observer->getEvent()->getOrder();
                if ($order->getShippingMethod() == 'hop_hop') {
                    if ($order instanceof \Magento\Framework\Model\AbstractModel) {
                        $statuses = $this->helper->getStatusOrderAllowed();

                        $orderStatus = $order->getStatus();

                        if (in_array($orderStatus, $statuses)) {
                            $this->shippingMethodHelper->createShipmentData($order);
                        }
                    }
                }
            }
            return $this;
        } catch (\Exception $e) {
            $this->helper->log($e->getMessage(), true);
        }
    }
}
