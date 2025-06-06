<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\SelectedPickUpPoint\CollectionFactory;
use Hop\Envios\Model\SelectedPickUpPointFactory;
use Hop\Envios\Model\ResourceModel\SelectedPickUpPoint as SelectedPickUpPointResource;
use Magento\Sales\Api\OrderRepositoryInterface;

class SelectedPickUpPointRepository
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var SelectedPickUpPointFactory
     */
    private $selectedPickUpPointFactory;

    /**
     * @var SelectedPickUpPointResource
     */
    private $selectedPickUpPointResource;

    /**
     * @param CollectionFactory $collectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param SelectedPickUpPointFactory $selectedPickUpPointFactory
     * @param SelectedPickUpPointResource $selectedPickUpPointResource
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        OrderRepositoryInterface $orderRepository,
        SelectedPickUpPointFactory $selectedPickUpPointFactory,
        SelectedPickUpPointResource $selectedPickUpPointResource
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->orderRepository = $orderRepository;
        $this->selectedPickUpPointFactory = $selectedPickUpPointFactory;
        $this->selectedPickUpPointResource = $selectedPickUpPointResource;
    }

    /**
     * Get selected pickup point by order ID
     *
     * @param int $orderId
     * @return SelectedPickUpPoint|null
     */
    public function getSelectedPickUpPointByOrderId($orderId)
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $quoteId = (int)$order->getQuoteId();

            if (!$quoteId) {
                throw new NoSuchEntityException(
                    new \Magento\Framework\Phrase(
                        __('Order with ID "%1" does not have an associated quote.', $orderId)
                    )
                );
            }
            return $this->getSelectedPointByQuoteId($quoteId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get selected pickup point by quote ID
     *
     * @param int $quoteId
     * @return SelectedPickUpPoint|null
     */
    public function getSelectedPointByQuoteId(int $quoteId): ?string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_id', $quoteId);

        return $collection->getFirstItem()->getId() ? $collection->getFirstItem() : null;
    }

    /**
     * Create new SelectedPickUpPoint instance
     *
     * @return SelectedPickUpPoint
     */
    public function create()
    {
        return $this->selectedPickUpPointFactory->create();
    }

    /**
     * Save SelectedPickUpPoint
     *
     * @param SelectedPickUpPoint $selectedPickUpPoint
     * @return void
     * @throws \Exception
     */
    public function save(SelectedPickUpPoint $selectedPickUpPoint)
    {
        try {
            $this->selectedPickUpPointResource->save($selectedPickUpPoint);
        } catch (\Exception $exception) {
            throw new \Exception(__('Could not save the selected pickup point: %1', $exception->getMessage()));
        }
    }

}