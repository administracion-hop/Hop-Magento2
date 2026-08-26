<?php

namespace Hop\Envios\Observer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Hop\Envios\Model\Shipping\NativeLabelGenerator;
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

    /**
     * @var NativeLabelGenerator
     */
    protected $nativeLabelGenerator;

    public function __construct(
        Data $helper,
        HopEnviosRepository $hopEnviosRepository,
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        Webservice $webservice,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        TrackFactory $trackFactory,
        TrackResource $trackResource,
        NativeLabelGenerator $nativeLabelGenerator
    ) {
        $this->helper = $helper;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->webservice = $webservice;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->trackFactory = $trackFactory;
        $this->trackResource = $trackResource;
        $this->nativeLabelGenerator = $nativeLabelGenerator;
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

            $this->removeDuplicateHopTracks($shipment);

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
            $hopEnvioId = (int)$hopEnvio->getEntityId();

            // Idempotency guard, keyed per shipment instead of per order: a shipment that
            // already has a hop_envios_shipment record was already sent to Hop (or explicitly
            // marked unsupported below) and must not be resubmitted on a later save of the
            // same shipment (e.g. when core writes the shipping label back onto it).
            $existingRecords = $this->hopEnviosShipmentRepository->getByHopEnvioId($hopEnvioId);
            $processedShipmentIds = array_map(
                static function ($record) {
                    return (int)$record->getShipmentId();
                },
                $existingRecords
            );

            $shipments = array_values(
                $this->shipmentCollectionFactory->create()
                    ->setOrderFilter($order)
                    ->getItems()
            );

            $unprocessed = array_values(array_filter(
                $shipments,
                static function ($s) use ($processedShipmentIds) {
                    return !in_array((int)$s->getId(), $processedShipmentIds, true);
                }
            ));

            if (empty($unprocessed)) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: shipment already processed for this Hop envio');
                return;
            }

            $this->webservice->setStoreId($storeId);

            // Hop creates one "envio" per order reference_id at dispatch time and exposes no
            // endpoint to add bultos to an envio that was already created. So only the very
            // first dispatch for this order (no per-shipment records yet) may call the API;
            // any shipment that shows up afterwards can't be represented in Hop and must not
            // silently inherit an earlier shipment's tracking number.
            if (!empty($existingRecords)) {
                foreach ($unprocessed as $i => $s) {
                    $this->helper->log(
                        '[ShipmentSaveAfter] shipment ' . $s->getId() . ' (order ' . $order->getId() . ')'
                        . ' appeared after this order\'s Hop envio was already dispatched; Hop has no'
                        . ' endpoint to add bultos afterwards. Marking as unsupported, no tracking assigned.',
                        true
                    );
                    $this->hopEnviosShipmentRepository->saveForShipment(
                        $hopEnvioId,
                        (int)$s->getId(),
                        count($existingRecords) + $i,
                        null,
                        null,
                        null,
                        'unsupported'
                    );
                }
                return;
            }

            if (count($shipments) === 1) {
                $result = $this->webservice->createShipping($order);
                if (is_string($result) && $result !== '') {
                    $hopEnvio->setInfoHop($result);
                    $hopEnvio->setStatusShipment('completed');
                    $this->hopEnviosRepository->save($hopEnvio);
                    $infoHop = json_decode($result, true);
                    $this->hopEnviosShipmentRepository->saveForShipment(
                        $hopEnvioId,
                        (int)$shipment->getId(),
                        0,
                        $infoHop['shipping_id'] ?? null,
                        $infoHop['tracking_nro'] ?? null,
                        $infoHop['label_url'] ?? null
                    );
                    if (!empty($infoHop['tracking_nro'])) {
                        $this->addTrackToShipment($shipment, $infoHop['tracking_nro']);
                    }
                    $this->nativeLabelGenerator->generate($shipment);
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
                        $this->nativeLabelGenerator->generate($s);
                    }
                } else {
                    $this->helper->log('Hop API error (multibulto) order: ' . $order->getId(), true);
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('SalesOrderShipmentSaveAfter: ' . $e->getMessage(), true);
        }
    }

    /**
     * Core LabelGenerator::create() adds its own track (same tracking number, different carrier
     * code) whenever a Hop label is generated, on top of the one this observer already saved.
     * Keep the first-saved track (lowest entity_id) and drop later duplicates by number.
     */
    private function removeDuplicateHopTracks($shipment)
    {
        $seenNumbers = [];
        foreach ($shipment->getAllTracks() as $track) {
            $number = $track->getTrackNumber();
            if (!$number) {
                continue;
            }
            if (isset($seenNumbers[$number])) {
                try {
                    $this->trackResource->delete($track);
                } catch (\Exception $e) {
                    $this->helper->log('removeDuplicateHopTracks: ' . $e->getMessage(), true);
                }
            } else {
                $seenNumbers[$number] = true;
            }
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
