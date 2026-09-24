<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\ResourceModel\HopEnvios\CollectionFactory;
use Hop\Envios\Model\HopEnviosFactory;
use Hop\Envios\Model\ResourceModel\HopEnvios as HopEnviosResource;
use Hop\Envios\Cron\GenarateShipment;

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
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return $item->getId() ? $item : null;
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
     * Fallidos cuyo próximo reintento ya venció.
     *
     * @return \Hop\Envios\Model\ResourceModel\HopEnvios\Collection
     */
    public function getRetryableFailed()
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status_shipment', GenarateShipment::SHIPMENT_STATUS_FAILED);
        $collection->addFieldToFilter('next_retry_at', ['lteq' => gmdate('Y-m-d H:i:s')]);
        return $collection;
    }

    /**
     * Registra un despacho fallido y, si es un error transitorio, agenda el próximo reintento
     * (ver DispatchError::retryDelayMinutes). Si no, queda en failed con next_retry_at NULL:
     * no se reintenta solo y queda para revisión manual (botón "Enviar a HOP").
     *
     * @param HopEnvios $hopEnvios
     * @param string $error
     * @param int|null $httpStatus
     * @return void
     */
    public function markFailed(HopEnvios $hopEnvios, $error, $httpStatus = null)
    {
        $attempts = (int)$hopEnvios->getAttempts() + 1;
        $code = DispatchError::classify((string)$error);
        $delay = DispatchError::retryDelayMinutes($attempts, $httpStatus, $code);

        $hopEnvios->setStatusShipment(GenarateShipment::SHIPMENT_STATUS_FAILED);
        $hopEnvios->setLastError((string)$error);
        $hopEnvios->setLastErrorCode($code);
        $hopEnvios->setAttempts($attempts);
        $hopEnvios->setNextRetryAt($delay ? gmdate('Y-m-d H:i:s', time() + $delay * 60) : null);
        $this->save($hopEnvios);
    }

    /**
     * @param HopEnvios $hopEnvios
     * @param string|null $infoHop
     * @return void
     */
    public function markCompleted(HopEnvios $hopEnvios, $infoHop = null)
    {
        if ($infoHop !== null) {
            $hopEnvios->setInfoHop($infoHop);
        }
        $hopEnvios->setStatusShipment(GenarateShipment::SHIPMENT_STATUS_COMPLETED);
        $hopEnvios->setLastError(null);
        $hopEnvios->setLastErrorCode(null);
        $hopEnvios->setNextRetryAt(null);
        $this->save($hopEnvios);
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
