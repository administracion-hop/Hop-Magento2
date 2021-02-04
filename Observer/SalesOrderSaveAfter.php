<?php
namespace Improntus\Hop\Observer;

use Improntus\Hop\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Improntus\Hop\Model\Webservice;

/**
 * Class SalesOrderSaveAfter
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Observer
 */
class SalesOrderSaveAfter implements ObserverInterface
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
     * @var \Improntus\Hop\Model\ImprontusHopFactory
     */
    protected $_improntusHopFactory;

    /**
     * SalesOrderSaveAfter constructor.
     * @param Data $data
     * @param Webservice $webservice
     * @param \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
     */
    public function __construct(
        Data $data,
        Webservice $webservice,
        \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
    ) {
        $this->_helper = $data;
        $this->_webservice = $webservice;
        $this->_improntusHopFactory = $improntusHopFactory;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            if ($this->_helper->isActive())
            {
                $order = $observer->getEvent()->getOrder();
                if($order->getShippingMethod() == 'hop_hop')
                {
                    if ($order instanceof \Magento\Framework\Model\AbstractModel)
                    {
                        $statuses = $this->_helper->getStatusOrderAllowed();

                        $orderStatus = $order->getStatus();

                        if(in_array($orderStatus, $statuses))
                        {
                            $improntusHop = $this->_improntusHopFactory->create();
                            $improntusHop = $improntusHop->getCollection()
                                ->addFieldToFilter('order_id', ['eq' => $order->getId()])
                                ->getFirstItem();

                            if (!count($improntusHop->getData()))
                            {
                                $improntusHop = $this->_improntusHopFactory->create();
                                $improntusHop->setOrderId($order->getId());
                                $improntusHop->setIncrementId($order->getIncrementId());
                                $improntusHop->save();
                            }

                            if(!$improntusHop->getInfoHop())
                            {
                                $result = $this->_webservice->createShipping($order);
                                if($result){
                                    $improntusHop->setInfoHop($result);
                                    $improntusHop->save();
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
