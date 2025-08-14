<?php

namespace Hop\Envios\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Hop\Envios\Logger\LoggerInterface;

class ClearHopDataAfterOrderPlace implements ObserverInterface
{
    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        CheckoutSession $checkoutSession,
        LoggerInterface $logger
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
    }

    /**
     * Clear hop_data from checkout session after order is placed
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        try {
            if ($this->checkoutSession->getHopData()) {
                $this->checkoutSession->unsHopData();
            }
        } catch (\Exception $e) {
            $this->logger->error('Hop_Envios: Error clearing hop_data from session: ' . $e->getMessage());
        }
    }
}