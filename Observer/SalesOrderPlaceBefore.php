<?php
namespace Hop\Envios\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\NoSuchEntityException as NoSuchEntityExceptionAlias;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Class SalesOrderPlaceBefore
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Observer
 */
class SalesOrderPlaceBefore implements ObserverInterface
{

    protected $_quoteRepository;

    /**
     * SalesOrderPlaceBefore constructor.
     * @param CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        CartRepositoryInterface $quoteRepository
    )
    {
        $this->_quoteRepository = $quoteRepository;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return $this|void
     * @throws NoSuchEntityExceptionAlias
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $quote = $this->_quoteRepository->get($order->getQuoteId());

        if($order->getShippingMethod() == \Hop\Envios\Model\Carrier\Hop::CARRIER_CODE . '_'
            . \Hop\Envios\Model\Carrier\Hop::CARRIER_CODE)
        {
            $order->setHopData($quote->getHopData());
        }

        return $this;
    }
}
