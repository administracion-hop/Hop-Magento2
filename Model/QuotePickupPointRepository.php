<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\QuotePickupPoint\CollectionFactory;
use Hop\Envios\Model\QuotePickupPointFactory;
use Hop\Envios\Model\ResourceModel\QuotePickupPoint as QuotePickupPointResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\CouldNotDeleteException;
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
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
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
     * @throws CouldNotSaveException
     */
    public function save(QuotePickupPoint $quotePickupPoint)
    {
        try {
            $this->resourceModel->save($quotePickupPoint);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(new Phrase('Could not save the quote pickup point: %1', [$e->getMessage()]), $e);
        }
    }

    /**
     * Delete QuotePickupPoint
     *
     * @param QuotePickupPoint $quotePickupPoint
     * @return bool
     * @throws CouldNotDeleteException
     */
    public function delete(QuotePickupPoint $quotePickupPoint)
    {
        try {
            $this->resourceModel->delete($quotePickupPoint);
            return true;
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(new Phrase('Could not delete the quote pickup point: %1', [$e->getMessage()]), $e);
        }
    }

    /**
     * Delete quote pickup point by quote ID
     *
     * @param int $quoteId
     * @return bool
     * @throws CouldNotDeleteException
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
        } catch (CouldNotDeleteException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(new Phrase('Could not delete the quote pickup point: %1', [$e->getMessage()]), $e);
        }
    }
}
