<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\HopEnviosShipment\CollectionFactory;
use Hop\Envios\Model\HopEnviosShipmentFactory;
use Hop\Envios\Model\ResourceModel\HopEnviosShipment as HopEnviosShipmentResource;

class HopEnviosShipmentRepository
{
    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var HopEnviosShipmentFactory
     */
    private $factory;

    /**
     * @var HopEnviosShipmentResource
     */
    private $resource;

    public function __construct(
        CollectionFactory $collectionFactory,
        HopEnviosShipmentFactory $factory,
        HopEnviosShipmentResource $resource
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->factory = $factory;
        $this->resource = $resource;
    }

    /**
     * @param int $shipmentId
     * @return HopEnviosShipment|null
     */
    public function getByShipmentId(int $shipmentId): ?HopEnviosShipment
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('shipment_id', $shipmentId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
    }

    /**
     * @param int $hopEnvioId
     * @return HopEnviosShipment[]
     */
    public function getByHopEnvioId(int $hopEnvioId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('hop_envio_id', $hopEnvioId);
        $collection->setOrder('bulto_index', 'ASC');
        return array_values($collection->getItems());
    }

    /**
     * @param int $hopEnvioId
     * @param int $shipmentId
     * @param int $bultoIndex
     * @param string|null $shippingId
     * @param string|null $trackingNro
     * @param string|null $labelUrl
     * @return HopEnviosShipment
     */
    public function saveForShipment(
        int $hopEnvioId,
        int $shipmentId,
        int $bultoIndex,
        ?string $shippingId,
        ?string $trackingNro,
        ?string $labelUrl,
        string $status = 'completed'
    ): HopEnviosShipment {
        $record = $this->factory->create();
        $record->setHopEnvioId($hopEnvioId);
        $record->setShipmentId($shipmentId);
        $record->setBultoIndex($bultoIndex);
        $record->setShippingId($shippingId);
        $record->setTrackingNro($trackingNro);
        $record->setLabelUrl($labelUrl);
        $record->setStatus($status);
        $this->resource->save($record);
        return $record;
    }

    /**
     * @param HopEnviosShipment $record
     * @return void
     */
    public function save(HopEnviosShipment $record): void
    {
        $this->resource->save($record);
    }

    /**
     * Records that were successfully dispatched to Hop (have a label to fetch) — the
     * candidate set for the native shipping_label retry cron. Caller still has to check
     * each shipment's own shipping_label, since this table doesn't know about that column.
     *
     * @return HopEnviosShipment[]
     */
    public function getCompletedWithLabel(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', 'completed');
        $collection->addFieldToFilter('label_url', ['notnull' => true]);
        return array_values($collection->getItems());
    }
}
