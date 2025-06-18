<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\SelectedPickupPoint\CollectionFactory;
use Hop\Envios\Model\SelectedPickupPointFactory;
use Hop\Envios\Model\ResourceModel\SelectedPickupPoint as SelectedPickupPointResource;
use Magento\Sales\Api\OrderRepositoryInterface;
use \Magento\Framework\Exception\NoSuchEntityException;
use \Magento\Framework\Phrase;
class SelectedPickupPointRepository
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
     * @var SelectedPickupPointFactory
     */
    private $selectedPickupPointFactory;

    /**
     * @var SelectedPickupPointResource
     */
    private $resourceModel;

    /**
     * @param CollectionFactory $collectionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param SelectedPickupPointFactory $selectedPickupPointFactory
     * @param SelectedPickupPointResource $resourceModel
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        OrderRepositoryInterface $orderRepository,
        SelectedPickupPointFactory $selectedPickupPointFactory,
        SelectedPickupPointResource $resourceModel
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->orderRepository = $orderRepository;
        $this->selectedPickupPointFactory = $selectedPickupPointFactory;
        $this->resourceModel = $resourceModel;
    }

    /**
     * Get selected pickup point by order ID
     *
     * @param int $orderId
     * @return SelectedPickupPoint|null
     */
    public function getByOrderId($orderId)
    {
        try {
            $order = $this->orderRepository->get($orderId);
            $quoteId = (int)$order->getQuoteId();

            if (!$quoteId) {
                throw new NoSuchEntityException(
                    new Phrase(
                        __('Order with ID "%1" does not have an associated quote.', $orderId)
                    )
                );
            }
            return $this->getByQuoteId($quoteId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get selected pickup point by quote ID
     *
     * @param int $quoteId
     * @return SelectedPickupPoint|null
     */
    public function getByQuoteId(int $quoteId)
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_id', $quoteId);

        return $collection->getFirstItem()->getId() ? $collection->getFirstItem() : null;
    }

    /**
     * Create new SelectedPickupPoint instance
     *
     * @return SelectedPickupPoint
     */
    public function create()
    {
        return $this->selectedPickupPointFactory->create();
    }

    /**
     * Save SelectedPickupPoint
     *
     * @param SelectedPickupPoint $selectedPickupPoint
     * @return void
     * @throws \Exception
     */
    public function save(SelectedPickupPoint $selectedPickupPoint)
    {
        try {
            $this->resourceModel->save($selectedPickupPoint);
        } catch (\Exception $exception) {
            throw new \Exception(__('Could not save the selected pickup point: %1', $exception->getMessage()));
        }
    }

    /**
     * Delete SelectedPickUpPoint
     *
     * @param SelectedPickUpPoint $selectedPickUpPoint
     * @return bool
     * @throws \Exception
     */
    public function delete(SelectedPickUpPoint $selectedPickUpPoint)
    {
        try {
            $this->resourceModel->delete($selectedPickUpPoint);
            return true;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\CouldNotDeleteException(
                new Phrase(__('Could not delete pickup point: %1', [$e->getMessage()]))
            );
        }
    }

    /**
     * Delete pickup point by ID
     *
     * @param int $quoteId
     * @return bool
     * @throws NoSuchEntityException
     */
    public function deleteByQuoteId($quoteId)
    {
        try {
            $model = $this->selectedPickupPointFactory->create();
            $this->resourceModel->load($model, $quoteId, 'quote_id');

            if (!$model->getId()) {
                return false;
            }

            return $this->delete($model);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\CouldNotDeleteException(
                new Phrase(__('Could not delete pickup point: %1', [$e->getMessage()]))
            );
        }
    }

}
