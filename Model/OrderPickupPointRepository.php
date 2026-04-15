<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\OrderPickupPoint\CollectionFactory;
use Hop\Envios\Model\OrderPickupPointFactory;
use Hop\Envios\Model\ResourceModel\OrderPickupPoint as OrderPickupPointResource;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Phrase;

class OrderPickupPointRepository
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var OrderPickupPointFactory
     */
    private $orderPickupPointFactory;

    /**
     * @var OrderPickupPointResource
     */
    private $resourceModel;

    /**
     * @param CollectionFactory $collectionFactory
     * @param OrderPickupPointFactory $orderPickupPointFactory
     * @param OrderPickupPointResource $resourceModel
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        OrderPickupPointFactory $orderPickupPointFactory,
        OrderPickupPointResource $resourceModel
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->orderPickupPointFactory = $orderPickupPointFactory;
        $this->resourceModel = $resourceModel;
    }

    /**
     * Get order pickup point by order ID
     *
     * @param int $orderId
     * @return OrderPickupPoint|null
     */
    public function getByOrderId(int $orderId)
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }

    /**
     * Create new OrderPickupPoint instance
     *
     * @return OrderPickupPoint
     */
    public function create()
    {
        return $this->orderPickupPointFactory->create();
    }

    /**
     * Save OrderPickupPoint
     *
     * @param OrderPickupPoint $orderPickupPoint
     * @return void
     * @throws CouldNotSaveException
     */
    public function save(OrderPickupPoint $orderPickupPoint)
    {
        try {
            $this->resourceModel->save($orderPickupPoint);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(new Phrase('Could not save the order pickup point: %1', [$e->getMessage()]), $e);
        }
    }

    /**
     * Delete OrderPickupPoint
     *
     * @param OrderPickupPoint $orderPickupPoint
     * @return bool
     * @throws CouldNotDeleteException
     */
    public function delete(OrderPickupPoint $orderPickupPoint)
    {
        try {
            $this->resourceModel->delete($orderPickupPoint);
            return true;
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(new Phrase('Could not delete the order pickup point: %1', [$e->getMessage()]), $e);
        }
    }
}
