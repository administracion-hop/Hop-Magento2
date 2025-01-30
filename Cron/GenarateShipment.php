<?php

namespace Hop\Envios\Cron;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Framework\DB\Transaction;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\ShipmentNotifier;
use Hop\Envios\Model\Carrier\Hop;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;

class GenarateShipment
{
    protected $orderFactory;
    protected $shipmentFactory;
    protected $trackFactory;
    protected $transaction;
    protected $logger;
    protected $shipmentNotifier;
    protected $hopCarrier;
    protected $resourceConnection;

    const SHIPMENT_STATUS_PENDING = 'pending';

    const SHIPMENT_STATUS_PROCESING = 'processing';
    const SHIPMENT_STATUS_COMPLETED = 'completed';
    const CARRIER_CODE_HOP = 'HOP';
    const TRACKING_NUMBER = '1234567890';

    public function __construct(
        OrderFactory $orderFactory,
        ShipmentFactory $shipmentFactory,
        TrackFactory $trackFactory,
        Transaction $transaction,
        LoggerInterface $logger,
        ShipmentNotifier $shipmentNotifier,
        Hop $hopCarrier,
        ResourceConnection $resourceConnection
    ) {
        $this->orderFactory = $orderFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->trackFactory = $trackFactory;
        $this->transaction = $transaction;
        $this->logger = $logger;
        $this->shipmentNotifier = $shipmentNotifier;
        $this->hopCarrier = $hopCarrier;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Execute the cron job to process pending orders.
     */
    public function execute()
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $hopEnviosTable = $this->resourceConnection->getTableName('hop_envios');

            // Obtener órdenes con estado 'pending'
            $orderIds = $this->getPendingOrders($connection, $hopEnviosTable);
            $this->logger->info('Ordenes pendientes encontradas: ' . count($orderIds));

            foreach ($orderIds as $orderId) {
                $this->processOrder($orderId, $connection, $hopEnviosTable);
            }

        } catch (\Exception $e) {
            $this->logger->error('Error en el cron de envíos: ' . $e->getMessage());
        }
    }

    /**
     * Obtener las órdenes pendientes.
     *
     * @param \Magento\Framework\DB\Adapter\Pdo\Mysql $connection
     * @param string $hopEnviosTable
     * @return array
     */
    protected function getPendingOrders($connection, $hopEnviosTable)
    {
        $sql = "SELECT order_id FROM " . $hopEnviosTable . " WHERE status_shipment = ?";
        return $connection->fetchCol($sql, [self::SHIPMENT_STATUS_PENDING]);
    }

    /**
     * Procesar cada orden pendiente.
     *
     * @param int $orderId
     * @param \Magento\Framework\DB\Adapter\Pdo\Mysql $connection
     * @param string $hopEnviosTable
     */
    protected function processOrder($orderId, $connection, $hopEnviosTable)
    {
        $order = $this->orderFactory->create()->load($orderId);

        if ($order->getId() && $order->canShip()) {
            $this->updateShipmentStatus($orderId, $connection, $hopEnviosTable, self::SHIPMENT_STATUS_PROCESING);

            $items = $this->prepareItemsForShipment($order);

            try {
                $shipment = $this->createShipment($order, $items);
                $track = $this->createTracking($shipment);
                $this->shipmentNotifier->notify($shipment);
                $this->transaction->addObject($shipment)
                    ->addObject($order->save())
                    ->save();

                $shipmentRequest = new \Magento\Framework\DataObject();
                $shipmentRequest->setData('order_shipment', $shipment);

                $this->updateOrderStatus($order);

                $labelResponse = $this->hopCarrier->_doShipmentRequest($shipmentRequest);
                $this->handleLabelResponse($labelResponse, $shipment, $order);

                $this->updateShipmentStatus($orderId, $connection, $hopEnviosTable, self::SHIPMENT_STATUS_COMPLETED);

                $this->logger->info('Shipment generated successfully for order ID: ' . $order->getId());
            } catch (\Exception $e) {
                $this->logger->error('Error generando el envío para la orden ' . $order->getId() . ': ' . $e->getMessage());
            }
        } else {
            $this->logger->warning('Orden no lista para envío o no existe: ' . $orderId);
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
     * @return \Magento\Sales\Model\Order\Shipment\Track
     */
    protected function createTracking($shipment)
    {
        $track = $this->trackFactory->create();
        $track->setCarrierCode('hop')
            ->setTitle(self::CARRIER_CODE_HOP)
            ->setTrackNumber(self::TRACKING_NUMBER);

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
     * Actualizar el estado del envío en la tabla 'hop_envios'.
     *
     * @param int $orderId
     * @param \Magento\Framework\DB\Adapter\Pdo\Mysql $connection
     * @param string $hopEnviosTable
     */
    protected function updateShipmentStatus($orderId, $connection, $hopEnviosTable, $status)
    {
        $updateData = ['status_shipment' => $status];
        $where = ['order_id = ?' => $orderId];
        $connection->update($hopEnviosTable, $updateData, $where);
    }
}
