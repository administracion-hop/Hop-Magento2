<?php

namespace Hop\Envios\Cron;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Framework\DB\Transaction;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\ShipmentNotifier;
use Magento\Sales\Model\Order;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

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
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var ShipmentCollectionFactory
     */
    protected $shipmentCollectionFactory;

    const SHIPMENT_STATUS_PENDING = 'pending';
    const SHIPMENT_STATUS_PROCESING = 'processing';
    const SHIPMENT_STATUS_COMPLETED = 'completed';

    public function __construct(
        OrderFactory $orderFactory,
        ShipmentFactory $shipmentFactory,
        Transaction $transaction,
        LoggerInterface $logger,
        ShipmentNotifier $shipmentNotifier,
        HopEnviosRepository $hopEnviosRepository,
        ShipmentCollectionFactory $shipmentCollectionFactory
    ) {
        $this->orderFactory = $orderFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->shipmentNotifier = $shipmentNotifier;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
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
     * Only acts on orders with zero existing shipments, so the one shipment it creates
     * always covers every shippable item at once and the order ends up fully dispatched
     * in that same action. If a shipment already exists (e.g. an admin is manually
     * splitting the order into several), this cron must never create a competing one —
     * it backs off permanently for that order (status_shipment stays 'pending', but the
     * shipment-count guard keeps skipping it on every future run too).
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

        $existingShipments = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('order_id', $order->getId())
            ->getSize();
        if ($existingShipments > 0) {
            $this->logger->info(
                __('Orden %1 ya tiene envíos existentes (probablemente despacho manual en curso); cron no interviene.', $order->getId())
            );
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
            $order->save();

            // Tracking + label already written above by SalesOrderShipmentSaveAfter,
            // fired synchronously by the transaction save. Just notify the customer.
            $this->shipmentNotifier->notify($shipment);

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
