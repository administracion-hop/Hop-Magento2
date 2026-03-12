<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\QuotePickupPoint\CollectionFactory;
use Hop\Envios\Model\QuotePickupPointFactory;
use Hop\Envios\Model\ResourceModel\QuotePickupPoint as QuotePickupPointResource;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;

class QuotePickupPointRepository
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var QuotePickupPointFactory
     */
    private $quotePickupPointFactory;

    /**
     * @var QuotePickupPointResource
     */
    private $resourceModel;

    /**
     * @param CollectionFactory $collectionFactory
     * @param QuotePickupPointFactory $quotePickupPointFactory
     * @param QuotePickupPointResource $resourceModel
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        QuotePickupPointFactory $quotePickupPointFactory,
        QuotePickupPointResource $resourceModel
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->quotePickupPointFactory = $quotePickupPointFactory;
        $this->resourceModel = $resourceModel;
    }

    /**
     * Get quote pickup point by quote ID
     *
     * @param int $quoteId
     * @return QuotePickupPoint|null
     */
    public function getByQuoteId(int $quoteId)
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('quote_id', $quoteId);

        return $collection->getFirstItem()->getId() ? $collection->getFirstItem() : null;
    }

    /**
     * Create new QuotePickupPoint instance
     *
     * @return QuotePickupPoint
     */
    public function create()
    {
        return $this->quotePickupPointFactory->create();
    }

    /**
     * Save QuotePickupPoint
     *
     * @param QuotePickupPoint $quotePickupPoint
     * @return void
     * @throws \Exception
     */
    public function save(QuotePickupPoint $quotePickupPoint)
    {
        try {
            $this->resourceModel->save($quotePickupPoint);
        } catch (\Exception $exception) {
            throw new \Exception(__('Could not save the quote pickup point: %1', $exception->getMessage()));
        }
    }

    /**
     * Delete QuotePickupPoint
     *
     * @param QuotePickupPoint $quotePickupPoint
     * @return bool
     * @throws \Exception
     */
    public function delete(QuotePickupPoint $quotePickupPoint)
    {
        try {
            $this->resourceModel->delete($quotePickupPoint);
            return true;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\CouldNotDeleteException(
                new Phrase(__('Could not delete quote pickup point: %1', [$e->getMessage()]))
            );
        }
    }

    /**
     * Delete quote pickup point by quote ID
     *
     * @param int $quoteId
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteByQuoteId(int $quoteId)
    {
        try {
            $model = $this->quotePickupPointFactory->create();
            $this->resourceModel->load($model, $quoteId, 'quote_id');

            if (!$model->getId()) {
                return false;
            }

            return $this->delete($model);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\CouldNotDeleteException(
                new Phrase(__('Could not delete quote pickup point: %1', [$e->getMessage()]))
            );
        }
    }
}
