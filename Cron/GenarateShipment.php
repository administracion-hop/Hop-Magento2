<?php

namespace Hop\Envios\Cron;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Framework\DB\Transaction;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\ShipmentNotifier;
use Hop\Envios\Model\Carrier\Hop;
use Magento\Sales\Model\Order;
use Hop\Envios\Model\HopEnviosRepository;

class GenarateShipment
{

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ShipmentFactory
     */
    protected $shipmentFactory;

    /**
     * @var TrackFactory
     */
    protected $trackFactory;

    /**
     * @var Transaction
     */
    protected $transaction;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ShipmentNotifier
     */
    protected $shipmentNotifier;

    /**
     * @var Hop
     */
    protected $hopCarrier;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    const SHIPMENT_STATUS_PENDING = 'pending';
    const SHIPMENT_STATUS_PROCESING = 'processing';
    const SHIPMENT_STATUS_COMPLETED = 'completed';
    const CARRIER_CODE_HOP = 'HOP';

    public function __construct(
        OrderFactory $orderFactory,
        ShipmentFactory $shipmentFactory,
        TrackFactory $trackFactory,
        Transaction $transaction,
        LoggerInterface $logger,
        ShipmentNotifier $shipmentNotifier,
        Hop $hopCarrier,
        HopEnviosRepository $hopEnviosRepository
    ) {
        $this->orderFactory = $orderFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->trackFactory = $trackFactory;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->shipmentNotifier = $shipmentNotifier;
        $this->hopCarrier = $hopCarrier;
        $this->hopEnviosRepository = $hopEnviosRepository;
    }

    /**
     * Execute the cron job to process pending orders.
     */
    public function execute()
    {
        try {
            /** @var \Hop\Envios\Model\ResourceModel\HopEnvios\Collection $pendingOrders */
            $pendingOrders = $this->getPendingOrders();
            $this->logger->info(__('Ordenes pendientes encontradas: ') . $pendingOrders->count());

            foreach ($pendingOrders as $pendingOrder) {
                $this->processOrder($pendingOrder);
            }
        } catch (\Exception $e) {
            $this->logger->error(__('Error en el cron de envíos: ') . $e->getMessage());
        }
    }

    /**
     * Obtener las órdenes pendientes.
     *
     * @return \Hop\Envios\Model\ResourceModel\HopEnvios\Collection
     */
    protected function getPendingOrders()
    {
        return $this->hopEnviosRepository->getCollectionByStatusShipment(self::SHIPMENT_STATUS_PENDING);
    }

    /**
     * Procesar cada orden pendiente.
     * Saves the Magento shipment first; the SalesOrderShipmentSaveAfter observer
     * then calls the Hop API synchronously and writes info_hop to DB.
     *
     * @param \Hop\Envios\Model\HopEnvios $hopEnvio
     */
    protected function processOrder($hopEnvio)
    {
        $order = $this->orderFactory->create()->load($hopEnvio->getOrderId());

        if (!$order->getId() || !$order->canShip()) {
            $this->logger->warning(__('Orden no lista para envío o no existe: ') . $hopEnvio->getOrderId());
            return;
        }

        $this->updateShipmentStatus($hopEnvio, self::SHIPMENT_STATUS_PROCESING);
        $items = $this->prepareItemsForShipment($order);

        try {
            $shipment = $this->createShipment($order, $items);

            $packageData = [
                "1" => [
                    "params" => [
                        "container" => "",
                        "weight" => "1",
                        "customs_value" => "100",
                        "length" => "",
                        "width" => "",
                        "height" => "",
                        "weight_units" => "POUND",
                        "dimension_units" => "INCH",
                        "content_type" => "",
                        "content_type_other" => ""
                    ],
                    "items" => []
                ]
            ];

            foreach ($order->getAllItems() as $item) {
                if ($item->getQtyShipped() > 0 && !$item->getIsVirtual()) {
                    $packageData["1"]["items"][$item->getId()] = [
                        "qty" => (string)$item->getQtyShipped(),
                        "customs_value" => (string)$item->getPrice(),
                        "price" => (string)$item->getPrice(),
                        "name" => $item->getName(),
                        "weight" => (string)$item->getWeight(),
                        "product_id" => (string)$item->getProductId(),
                        "order_item_id" => (string)$item->getId()
                    ];
                }
            }

            $shipment->setData('packages', $packageData);

            // Save shipment — fires sales_order_shipment_save_after synchronously.
            // The observer calls the Hop API and writes info_hop (or hop_envios_shipment).
            $this->transaction->addObject($shipment)
                ->addObject($order->save())
                ->save();

            $this->updateOrderStatus($order);

            // Read tracking + label from DB (written by the observer above)
            $shipmentRequest = new \Magento\Framework\DataObject();
            $shipmentRequest->setData('order_shipment', $shipment);

            try {
                $labelResponse = $this->hopCarrier->_doShipmentRequest($shipmentRequest);
            } catch (\Exception $labelException) {
                $this->logger->error(__('Error obteniendo label para orden ') . $order->getId() . ': ' . $labelException->getMessage());
                $this->updateShipmentStatus($hopEnvio, self::SHIPMENT_STATUS_COMPLETED);
                return;
            }

            if ($labelResponse) {
                $trackingNumber = $labelResponse->getTrackingNumber();
                $labelUrl       = $labelResponse->getShippingLabelContent();

                if ($trackingNumber) {
                    $this->createTracking($shipment, $trackingNumber);
                }
                if ($labelUrl) {
                    $shipment->setShippingLabel($labelUrl);
                }

                $this->shipmentNotifier->notify($shipment);
                $this->transaction->addObject($shipment)
                    ->addObject($order->save())
                    ->save();

                $this->logger->info('Shipping label generated for order: ' . $order->getId());
            }

            $this->updateShipmentStatus($hopEnvio, self::SHIPMENT_STATUS_COMPLETED);
            $this->logger->info(__('Shipment generated successfully for order ID: ') . $order->getId());
        } catch (\Exception $e) {
            $this->logger->error(__('Error generando el envío para la orden ') . $order->getId() . ': ' . $e->getMessage());
        }
    }

    /**
     * Preparar los items de la orden para el envío.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return array
     */
    protected function prepareItemsForShipment($order)
    {
        $items = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getQtyToShip() > 0 && !$item->getIsVirtual()) {
                $items[$item->getId()] = $item->getQtyToShip();
            }
        }
        return $items;
    }

    /**
     * Crear el envío para la orden.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $items
     * @return \Magento\Sales\Model\Order\Shipment
     */
    protected function createShipment($order, $items)
    {
        $shipment = $this->shipmentFactory->create($order, $items);
        $shipment->register();
        $shipment->getOrder()->setCustomerNoteNotify(true);

        return $shipment;
    }

    /**
     * Crear un tracking para el envío.
     *
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @param string $trackingNumber
     * @return \Magento\Sales\Model\Order\Shipment\Track
     */
    protected function createTracking($shipment, $trackingNumber)
    {
        $track = $this->trackFactory->create();
        $track->setCarrierCode('hop')
            ->setTitle(self::CARRIER_CODE_HOP);
        if ($trackingNumber) {
            $track->setTrackNumber($trackingNumber);
        }
        $shipment->addTrack($track);

        return $track;
    }

    /**
     * Manejar la respuesta de la etiqueta de envío.
     *
     * @param \Magento\Framework\DataObject|null $labelResponse
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @param \Magento\Sales\Model\Order $order
     */
    protected function handleLabelResponse($labelResponse, $shipment, $order)
    {
        if ($labelResponse) {
            $trackingNumber = $labelResponse->getTrackingNumber();
            $labelUrl = $labelResponse->getShippingLabelContent();

            if ($trackingNumber && $labelUrl) {
                $shipment->setShippingLabel($labelUrl);
                $this->transaction->addObject($shipment)
                    ->addObject($order->save())
                    ->save();
                $this->logger->info('Shipping label generated successfully for order ID: ' . $order->getId());
            } else {
                $this->logger->error('Error: No tracking number or label URL found.');
            }
        } else {
            $this->logger->error('Error: Failed to generate shipping label.');
        }
    }

    /**
     * Actualizar el estado de la orden a "processing".
     *
     * @param \Magento\Sales\Model\Order $order
     */
    protected function updateOrderStatus($order)
    {
        $order->setState(Order::STATE_PROCESSING)
            ->setStatus(Order::STATE_PROCESSING);
    }

    /**
     *
     * @param \Hop\Envios\Model\HopEnvios $hopEnvio
     * @param string $status
     */
    protected function updateShipmentStatus($hopEnvio, $status)
    {
        $hopEnvio->setStatusShipment($status);
        $this->hopEnviosRepository->save($hopEnvio);
    }
}
