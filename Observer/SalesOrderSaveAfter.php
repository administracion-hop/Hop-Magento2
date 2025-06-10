<?php
namespace Hop\Envios\Observer;

use Hop\Envios\Helper\Data;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\Webservice;

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
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * SalesOrderSaveAfter constructor.
     * @param Data $data
     * @param Webservice $webservice
     * @param HopEnviosRepository $hopEnviosRepository
     */
    public function __construct(
        Data $data,
        Webservice $webservice,
        HopEnviosRepository $hopEnviosRepository
    ) {
        $this->helper = $data;
        $this->webservice = $webservice;
        $this->hopEnviosRepository = $hopEnviosRepository;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {

        try {
            if ($this->helper->isActive())
            {
                $order = $observer->getEvent()->getOrder();
                if($order->getShippingMethod() == 'hop_hop')
                {
                    if ($order instanceof \Magento\Framework\Model\AbstractModel)
                    {
                        $statuses = $this->helper->getStatusOrderAllowed();

                        $orderStatus = $order->getStatus();

                        if(in_array($orderStatus, $statuses))
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
                                }
                            }
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
