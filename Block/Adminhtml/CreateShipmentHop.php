<?php

namespace Hop\Envios\Block\Adminhtml;

use Hop\Envios\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Hop\Envios\Model\Webservice;
use Magento\Shipping\Block\Adminhtml\View;

/**
 * Class SalesOrderSaveAfter
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Observer
 */
class CreateShipmentHop extends \Magento\Backend\Block\Template
{

    /**
     * @var Data
     */
    protected $_helper;

    /**
     * @var Webservice
     */
    protected $_webservice;

    /**
     * @var \Hop\Envios\Model\HopEnviosFactory
     */
    protected $_hopEnviosFactory;

    /**
     * @var View;
     */
    protected $_shipment;

    /**
     * SalesOrderSaveAfter constructor.
     * @param Data $data
     * @param Webservice $webservice
     * @param \Hop\Envios\Model\HopEnviosFactory $hopEnviosFactory
     * @param View $shipment
     */
    public function __construct(
        Data $data,
        Webservice $webservice,
        \Hop\Envios\Model\HopEnviosFactory $hopEnviosFactory,
        View $shipment
    ) {
        $this->_helper = $data;
        $this->_webservice = $webservice;
        $this->_hopEnviosFactory = $hopEnviosFactory;
        $this->_shipment = $shipment;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function crearShimentHop()
    {
        try {
            if ($this->_helper->isActive())
            {
                $shipment = $this->_shipment->getShipment();
                $order =  $shipment->getOrder();
                if($order->getShippingMethod() == 'hop_hop')
                {
                    if ($order instanceof \Magento\Framework\Model\AbstractModel)
                    {
                        $statuses = $this->_helper->getStatusOrderAllowed();

                        $orderStatus = $order->getStatus();

                        if(in_array($orderStatus, $statuses))
                        {
                            $hopEnvios = $this->_hopEnviosFactory->create();
                            $hopEnvios = $hopEnvios->getCollection()
                                ->addFieldToFilter('order_id', ['eq' => $order->getId()])
                                ->getFirstItem();

                            if (!count($hopEnvios->getData()))
                            {
                                $hopEnvios = $this->_hopEnviosFactory->create();
                                $hopEnvios->setOrderId($order->getId());
                                $hopEnvios->setIncrementId($order->getIncrementId());
                                $hopEnvios->save();
                            }

                            if(!$hopEnvios->getInfoHop())
                            {
                                $result = $this->_webservice->createShipping($order);
                                if(!isset($result['error'])){
                                    $hopEnvios->setInfoHop($result);
                                    $hopEnvios->save();
                                } else {
                                    $order->setShippingDescription($result['error']);
                                    $order->getResource()->saveAttribute($order, "shipping_description");
                                }
                            }
                        }
                    }
                }
            }
            return $this;
        } catch (\Exception $e) {
            $this->_helper->log($e->getMessage(), true);
        }
    }

}