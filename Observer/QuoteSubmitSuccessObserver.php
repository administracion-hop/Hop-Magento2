<?php
declare(strict_types=1);

namespace Hop\Envios\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Hop\Envios\Model\QuotePickupPointRepository;
use Hop\Envios\Model\OrderPickupPointRepository;
use Hop\Envios\Logger\LoggerInterface;

class QuoteSubmitSuccessObserver implements ObserverInterface
{
    /**
     * @var QuotePickupPointRepository
     */
    private $quotePickupPointRepository;

    /**
     * @var OrderPickupPointRepository
     */
    private $orderPickupPointRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param QuotePickupPointRepository $quotePickupPointRepository
     * @param OrderPickupPointRepository $orderPickupPointRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        QuotePickupPointRepository $quotePickupPointRepository,
        OrderPickupPointRepository $orderPickupPointRepository,
        LoggerInterface $logger
    ) {
        $this->quotePickupPointRepository = $quotePickupPointRepository;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $observer->getEvent()->getQuote();

        if (!$order || !$order->getId()) {
            return;
        }

        $quoteId = (int)($quote ? $quote->getId() : $order->getQuoteId());
        if (!$quoteId) {
            return;
        }

        try {
            $quotePickupPoint = $this->quotePickupPointRepository->getByQuoteId($quoteId);
            if (!$quotePickupPoint) {
                return;
            }

            $orderPickupPoint = $this->orderPickupPointRepository->getByOrderId((int)$order->getId())
                ?? $this->orderPickupPointRepository->create();

            $orderPickupPoint->setOrderId((int)$order->getId());
            $orderPickupPoint->setOriginalPickupPointId($quotePickupPoint->getData('original_pickup_point_id'));
            $orderPickupPoint->setPickupPointId($quotePickupPoint->getData('pickup_point_id'));
            $orderPickupPoint->setOriginalShippingDescription($quotePickupPoint->getData('original_shipping_description'));
            $orderPickupPoint->setOriginalZipCode($quotePickupPoint->getData('original_zip_code'));

            $this->orderPickupPointRepository->save($orderPickupPoint);
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Hop_Envios - Error al copiar pickup point de quote %d a order %d: %s',
                $quoteId,
                $order->getId(),
                $e->getMessage()
            ));
        }
    }
}
