<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\HopEnvios\CollectionFactory;
use Hop\Envios\Model\HopEnviosFactory;
use Hop\Envios\Model\ResourceModel\HopEnvios as HopEnviosResource;

class HopEnviosRepository
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var HopEnviosFactory
     */
    private $hopEnviosFactory;

    /**
     * @var HopEnviosResource
     */
    private $hopEnviosResource;

    /**
     * @param CollectionFactory $collectionFactory
     * @param HopEnviosFactory $hopEnviosFactory
     * @param HopEnviosResource $hopEnviosResource
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        HopEnviosFactory $hopEnviosFactory,
        HopEnviosResource $hopEnviosResource
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->hopEnviosFactory = $hopEnviosFactory;
        $this->hopEnviosResource = $hopEnviosResource;
    }


    /**
     * Get selected pickup point by quote ID
     *
     * @param int $quoteId
     * @return HopEnvios|null
     */
    public function getByOrderId(int $orderId)
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);

        return $collection->getFirstItem()->getId() ? $collection->getFirstItem() : null;
    }

    /**
     * @param string $statusShipment
     * @return \Hop\Envios\Model\ResourceModel\HopEnvios\Collection
     */
    public function getCollectionByStatusShipment($statusShipment)
    {
        /** @var \Hop\Envios\Model\ResourceModel\HopEnvios\Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status_shipment', $statusShipment);
        return $collection;
    }

    /**
     * Create new HopEnvios instance
     *
     * @return HopEnvios
     */
    public function create()
    {
        return $this->hopEnviosFactory->create();
    }

    /**
     * Save HopEnvios
     *
     * @param HopEnvios $hopEnvios
     * @return void
     * @throws \Exception
     */
    public function save(HopEnvios $hopEnvios)
    {
        try {
            $this->hopEnviosResource->save($hopEnvios);
        } catch (\Exception $exception) {
            throw new \Exception(__('Could not save the selected pickup point: %1', $exception->getMessage()));
        }
    }

}
