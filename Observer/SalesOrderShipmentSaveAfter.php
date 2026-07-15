<?php

namespace Hop\Envios\Observer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Hop\Envios\Model\Webservice;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track as TrackResource;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

class SalesOrderShipmentSaveAfter implements ObserverInterface
{
    /**
     * @var Data
     */
    protected $helper;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var HopEnviosShipmentRepository
     */
    protected $hopEnviosShipmentRepository;

    /**
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var ShipmentCollectionFactory
     */
    protected $shipmentCollectionFactory;

    /**
     * @var TrackFactory
     */
    protected $trackFactory;

    /**
     * @var TrackResource
     */
    protected $trackResource;

    public function __construct(
        Data $helper,
        HopEnviosRepository $hopEnviosRepository,
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        Webservice $webservice,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        TrackFactory $trackFactory,
        TrackResource $trackResource
    ) {
        $this->helper = $helper;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->webservice = $webservice;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->trackFactory = $trackFactory;
        $this->trackResource = $trackResource;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try {
            $shipment = $observer->getEvent()->getShipment();
            if (!$shipment) {
                return;
            }
            $order = $shipment->getOrder();
            if (!$order || !$order->getId()) {
                return;
            }

            $storeId = $order->getStoreId();

            $this->helper->log('[ShipmentSaveAfter] orderId=' . $order->getId() . ' shippingMethod=' . $order->getShippingMethod() . ' canShip=' . ($order->canShip() ? '1' : '0') . ' isActive=' . ($this->helper->isActive($storeId) ? '1' : '0'));

            if (!$this->helper->isActive($storeId)) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: isActive=false', true);
                return;
            }
            if ($order->getShippingMethod() !== 'hop_hop') {
                $this->helper->log('[ShipmentSaveAfter] EXIT: shippingMethod=' . $order->getShippingMethod(), true);
                return;
            }
            // Only proceed when all items are now shipped
            if ($order->canShip()) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: canShip=true (items still pending)', true);
                return;
            }

            $hopEnvio = $this->hopEnviosRepository->getByOrderId((int)$order->getId());
            if (!$hopEnvio) {
                $hopEnvio = $this->hopEnviosRepository->create();
                $hopEnvio->setOrderId((int)$order->getId());
                $hopEnvio->setIncrementId($order->getIncrementId());
                $this->hopEnviosRepository->save($hopEnvio);
            }
            // Guard: already processed (idempotency for subsequent saves like label writes)
            if ($hopEnvio->getStatusShipment() === 'completed') {
                $this->helper->log('[ShipmentSaveAfter] EXIT: status already completed');
                return;
            }

            $shipments = array_values(
                $this->shipmentCollectionFactory->create()
                    ->setOrderFilter($order)
                    ->getItems()
            );
            $count = count($shipments);

            $this->webservice->setStoreId($storeId);

            if ($count === 1) {
                $result = $this->webservice->createShipping($order);
                if (is_string($result) && $result !== '') {
                    $hopEnvio->setInfoHop($result);
                    $hopEnvio->setStatusShipment('completed');
                    $this->hopEnviosRepository->save($hopEnvio);
                    $infoHop = json_decode($result, true);
                    if (!empty($infoHop['tracking_nro'])) {
                        $this->addTrackToShipment($shipment, $infoHop['tracking_nro']);
                    }
                } elseif (is_array($result) && isset($result['error'])) {
                    $this->helper->log('Hop API error (single): ' . $result['error'], true);
                }
            } else {
                $ok = $this->webservice->createShippingMultibulto(
                    $order,
                    $shipments,
                    (int)$hopEnvio->getEntityId()
                );
                if ($ok) {
                    $hopEnvio->setStatusShipment('completed');
                    $this->hopEnviosRepository->save($hopEnvio);
                    foreach ($shipments as $s) {
                        $hopShipment = $this->hopEnviosShipmentRepository->getByShipmentId((int)$s->getId());
                        if ($hopShipment && $hopShipment->getTrackingNro()) {
                            $this->addTrackToShipment($s, $hopShipment->getTrackingNro());
                        }
                    }
                } else {
                    $this->helper->log('Hop API error (multibulto) order: ' . $order->getId(), true);
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('SalesOrderShipmentSaveAfter: ' . $e->getMessage(), true);
        }
    }

    private function addTrackToShipment($shipment, $trackingNumber)
    {
        try {
            $track = $this->trackFactory->create();
            $track->setCarrierCode('hop')
                ->setTitle('Hop Envíos')
                ->setTrackNumber($trackingNumber)
                ->setParentId($shipment->getId())
                ->setOrderId($shipment->getOrderId());
            $this->trackResource->save($track);
        } catch (\Exception $e) {
            $this->helper->log('addTrackToShipment: ' . $e->getMessage(), true);
        }
    }
}
