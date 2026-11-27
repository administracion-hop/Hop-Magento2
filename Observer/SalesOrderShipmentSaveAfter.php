<?php

namespace Hop\Envios\Observer;

use Hop\Envios\Cron\GenarateShipment;
use Hop\Envios\Helper\Data;
use Hop\Envios\Model\DispatchError;
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
        $shipment = $observer->getEvent()->getShipment();
        if ($shipment) {
            $this->dispatch($shipment);
        }
    }

    /**
     * Despacha a Hop el pedido del envío. Público para que el reintento (cron / botón
     * "Enviar a HOP") pueda repetir este camino sobre un envío de Magento ya creado.
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return bool|null true = despachado, false = Hop rechazó (queda failed), null = no aplicaba
     */
    public function dispatch($shipment)
    {
        try {
            $order = $shipment->getOrder();
            if (!$order || !$order->getId()) {
                return null;
            }

            $storeId = $order->getStoreId();

            $this->helper->log('[ShipmentSaveAfter] orderId=' . $order->getId() . ' shippingMethod=' . $order->getShippingMethod() . ' canShip=' . ($order->canShip() ? '1' : '0') . ' isActive=' . ($this->helper->isActive($storeId) ? '1' : '0'));

            if (!$this->helper->isActive($storeId)) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: isActive=false', true);
                return null;
            }
            if ($order->getShippingMethod() !== 'hop_hop') {
                $this->helper->log('[ShipmentSaveAfter] EXIT: shippingMethod=' . $order->getShippingMethod(), true);
                return null;
            }

            $this->removeDuplicateHopTracks($shipment);

            // Only proceed when all items are now shipped
            if ($order->canShip()) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: canShip=true (items still pending)', true);
                return null;
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

            // Magento dispara sales_order_shipment_save_after antes de guardar los artículos del
            // envío (VersionControl\AbstractDb::processAfterSaves llama a afterSave() antes de
            // processRelations()): la copia de este envío que acaba de leer la colección todavía no
            // tiene artículos, y el bulto viajaba vacío a Hop (valor y peso 0, sin medidas), que
            // rechazaba el multibulto entero. El envío que disparó el evento los tiene en memoria.
            foreach ($shipments as $i => $loaded) {
                if ((int)$loaded->getId() === (int)$shipment->getId()) {
                    $shipments[$i] = $shipment;
                }
            }

            $unprocessed = array_values(array_filter(
                $shipments,
                static function ($s) use ($processedShipmentIds) {
                    return !in_array((int)$s->getId(), $processedShipmentIds, true);
                }
            ));

            if (empty($unprocessed)) {
                $this->helper->log('[ShipmentSaveAfter] EXIT: shipment already processed for this Hop envio');
                return $this->settleAlreadySent($hopEnvio, $existingRecords, $shipments);
            }

            // Ya despachado por el camino directo (createShipmentData, sin envío de Magento): no
            // volver a llamar a Hop — respondería "reference id ya está en uso" y, si la
            // recuperación no coincide, marcaría failed un pedido que sí está despachado. Se le
            // asigna al envío el tracking que ya existe.
            if (empty($existingRecords) && $hopEnvio->getInfoHop()) {
                $infoHop = json_decode($hopEnvio->getInfoHop(), true) ?: [];
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
                $this->helper->log('[ShipmentSaveAfter] order ' . $order->getId() . ' ya despachada por Enviar a HOP: se reusa su tracking');
                return null;
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
                return null;
            }

            if (count($shipments) === 1) {
                $result = $this->webservice->createShipping($order);
                if (is_string($result) && $result !== '') {
                    $this->hopEnviosRepository->markCompleted($hopEnvio, $result);
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
                    return true;
                }
                $error = (is_array($result) && isset($result['error']))
                    ? $result['error']
                    : __('No se pudo generar el envío en Hop: el pedido no tiene punto de retiro (No Hop Data).');
                $this->helper->log('Hop API error (single): ' . $error, true);
                $this->hopEnviosRepository->markFailed(
                    $hopEnvio,
                    $error,
                    $this->webservice->getLastStatus(),
                    $this->webservice->wasRejectedByCredentials() ? DispatchError::CODE_CREDENTIALS : null
                );
                return false;
            } else {
                $ok = $this->webservice->createShippingMultibulto(
                    $order,
                    $shipments,
                    (int)$hopEnvio->getEntityId()
                );
                if ($ok) {
                    $this->hopEnviosRepository->markCompleted($hopEnvio);
                    foreach ($shipments as $s) {
                        $hopShipment = $this->hopEnviosShipmentRepository->getByShipmentId((int)$s->getId());
                        if ($hopShipment && $hopShipment->getTrackingNro()) {
                            $this->addTrackToShipment($s, $hopShipment->getTrackingNro());
                        }
                        $this->nativeLabelGenerator->generate($s);
                    }
                    return true;
                }
                // Si Hop respondió bulto por bulto, los aceptados ya están creados y todos los bultos
                // quedaron con registro en hop_envios_shipment: un reintento no tiene qué mandar (y
                // Hop no admite sumar bultos), así que no se ofrece reintentar.
                $partial = !empty($this->hopEnviosShipmentRepository->getByHopEnvioId($hopEnvioId));
                $this->helper->log('Hop API error (multibulto) order: ' . $order->getId(), true);
                $this->hopEnviosRepository->markFailed(
                    $hopEnvio,
                    $this->webservice->getLastMultibultoError() ?: __('No se pudo generar el envío multibulto en Hop.'),
                    $this->webservice->getLastStatus(),
                    $partial
                        ? DispatchError::CODE_MULTIBULTO_PARTIAL
                        : ($this->webservice->wasRejectedByCredentials() ? DispatchError::CODE_CREDENTIALS : null)
                );
                return false;
            }
        } catch (\Exception $e) {
            $this->helper->log('SalesOrderShipmentSaveAfter: ' . $e->getMessage(), true);
            // Sólo si Hop no llegó a aceptarlo: un fallo posterior (ej. la etiqueta) no deshace el despacho.
            if (isset($hopEnvio) && $hopEnvio->getStatusShipment() !== GenarateShipment::SHIPMENT_STATUS_COMPLETED) {
                $this->hopEnviosRepository->markFailed($hopEnvio, $e->getMessage());
                return false;
            }
        }
        return null;
    }

    /**
     * Todos los envíos de Magento del pedido ya se mandaron a Hop, pero el despacho no quedó
     * registrado como despachado ni como fallido (las versiones anteriores a la tarea 18065 no
     * registraban el fallo de un multibulto y lo dejaban pendiente). No se llama a Hop: se
     * registra lo que ya pasó, según los bultos, para que la grilla, "Enviar a HOP" y "Despachar
     * a Hop" muestren el motivo en lugar de un error sin explicación.
     *
     * - Algún bulto rechazado: es un multibulto aceptado a medias ("Parcial").
     * - Todos los bultos mandados con número de seguimiento: Hop aceptó todo ("Despachado"). Como en el
     *   despacho que sale bien, cada envío de Magento recibe su número de seguimiento (si no lo
     *   tiene) y su etiqueta.
     * - Otra cosa: no se toca; queda para revisión manual.
     *
     * @param \Hop\Envios\Model\HopEnvios $hopEnvio
     * @param \Hop\Envios\Model\HopEnviosShipment[] $records
     * @param \Magento\Sales\Model\Order\Shipment[] $shipments envíos de Magento del pedido
     * @return bool|null mismo contrato que dispatch(): true = despachado, false = parcial,
     *     null = no había nada que registrar
     */
    private function settleAlreadySent($hopEnvio, array $records, array $shipments)
    {
        $status = $hopEnvio->getStatusShipment();
        if (!$records
            || $status === GenarateShipment::SHIPMENT_STATUS_COMPLETED
            || $status === GenarateShipment::SHIPMENT_STATUS_FAILED
        ) {
            return null;
        }

        // Solo cuentan los bultos que se mandaron a Hop: los envíos agregados después del despacho
        // quedan como 'unsupported' y nunca llegaron a Hop.
        $sent = 0;
        $rejected = 0;
        $tracked = 0;
        foreach ($records as $record) {
            if ($record->getStatus() === GenarateShipment::SHIPMENT_STATUS_FAILED) {
                $sent++;
                $rejected++;
            } elseif ($record->getStatus() === GenarateShipment::SHIPMENT_STATUS_COMPLETED) {
                $sent++;
                $tracked += $record->getTrackingNro() ? 1 : 0;
            }
        }

        if ($rejected) {
            $this->helper->log(
                '[ShipmentSaveAfter] order ' . $hopEnvio->getOrderId()
                . ': bultos ya procesados con rechazos, se registra como multibulto parcial',
                true
            );
            $this->hopEnviosRepository->markFailed(
                $hopEnvio,
                DispatchError::multibultoPartialMessage($rejected, $sent),
                null,
                DispatchError::CODE_MULTIBULTO_PARTIAL
            );
            return false;
        }
        if ($sent && $tracked === $sent) {
            $this->helper->log(
                '[ShipmentSaveAfter] order ' . $hopEnvio->getOrderId()
                . ': bultos ya aceptados por Hop, se registra como despachado'
            );
            $this->hopEnviosRepository->markCompleted($hopEnvio);
            $trackingByShipment = [];
            foreach ($records as $record) {
                $trackingByShipment[(int)$record->getShipmentId()] = $record->getTrackingNro();
            }
            foreach ($shipments as $s) {
                $tracking = $trackingByShipment[(int)$s->getId()] ?? null;
                $hasTrack = false;
                foreach ($s->getAllTracks() as $track) {
                    $hasTrack = $hasTrack || $track->getTrackNumber() === $tracking;
                }
                if ($tracking && !$hasTrack) {
                    $this->addTrackToShipment($s, $tracking);
                }
                $this->nativeLabelGenerator->generate($s);
            }
            return true;
        }
        return null;
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
