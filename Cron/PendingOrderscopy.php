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

class PendingOrderscopy
{
    protected $orderFactory;
    protected $shipmentFactory;
    protected $trackFactory;
    protected $transaction;
    protected $logger;
    protected $shipmentNotifier;
    protected $hopCarrier;
    protected $resourceConnection;

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

    public function execute()
    {
        try {
            // Obtener la conexión a la base de datos
            $connection = $this->resourceConnection->getConnection();

            // Obtener el nombre de la tabla 'hop_envios' (o donde se almacenen los estados de los envíos)
            $hopTestTable = $this->resourceConnection->getTableName('hop_envios');

            // Obtiene las órdenes con estado 'pending'
            $sql = "SELECT order_id FROM " . $hopTestTable . " WHERE status_shipment = 'pending'";

            // Ejecuta la consulta y obtiene los resultados
            $orderIds = $connection->fetchCol($sql);

            $this->logger->info('Ordenes pendientes encontradas: ' . count($orderIds));

            // Procesar las órdenes
            foreach ($orderIds as $orderId) {
                // Cargar la orden
                $order = $this->orderFactory->create()->load($orderId);

                if ($order->getId() && $order->canShip()) {
                    $this->logger->info('Procesando orden para envío: ' . $order->getIncrementId());

                    // Preparar los productos para el envío
                    $items = [];
                    foreach ($order->getAllItems() as $item) {
                        if ($item->getQtyToShip() > 0 && !$item->getIsVirtual()) {
                            $items[$item->getId()] = $item->getQtyToShip(); // incluye la cantidad a enviar
                        }
                    }

                    try {
                        // Crea el envío
                        $shipment = $this->shipmentFactory->create($order, $items);
                        $shipment->register();
                        $shipment->getOrder()->setCustomerNoteNotify(true);

                        // **2. Crear y asignar el tracking al shipment**
                        $track = $this->trackFactory->create();
                        $track->setCarrierCode('Hop')
                            ->setTitle('Hop')
                            ->setTrackNumber('1234567890');

                        // Notifica al cliente
                        $this->shipmentNotifier->notify($shipment);

                        // Guarda el envío y la orden en una transacción
                        $this->transaction->addObject($shipment)
                            ->addObject($order->save())
                            ->save();

                        // Llamar al método del carrier personalizado
                        $shipmentRequest = new \Magento\Framework\DataObject();
                        $shipmentRequest->setData('order_shipment', $shipment);

                        // Invocar el método del carrier para generar la etiqueta
                        $labelResponse = $this->hopCarrier->_doShipmentRequest($shipmentRequest);

                        // Verifica que la respuesta tenga el tracking number y la URL de la etiqueta
                        if ($labelResponse) {
                            $trackingNumber = $labelResponse->getTrackingNumber();
                            $labelUrl = $labelResponse->getShippingLabelContent();

                            if ($trackingNumber && $labelUrl) {
                                // Asigna la etiqueta al shipment
                                $shipment->setShippingLabel($labelUrl);
                                $this->transaction->addObject($shipment)
                                    ->save();
                                $this->logger->info('Shipping label generated successfully for order ID: ' . $order->getId());
                            } else {
                                $this->logger->error('Error: No tracking number or label URL found.');
                            }
                        } else {
                            $this->logger->error('Error: Failed to generate shipping label.');
                        }

                        // Cambia el estado de la orden
                        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING)
                            ->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

                        // **Actualizar la tabla 'hop_envios'** después de procesar el envío
                        $updateData = ['status_shipment' => 'completed'];  // Actualiza el estado a 'completed'
                        $where = ['order_id = ?' => $orderId];  // Condición para identificar la fila a actualizar
                        $connection->update($hopTestTable, $updateData, $where);

                        // Registra un mensaje de éxito
                        $this->logger->info('Shipment generated successfully for order ID: ' . $order->getId());

                    } catch (\Exception $e) {
                        $this->logger->error('Error generando el envío para la orden ' . $order->getId() . ': ' . $e->getMessage());
                    }
                } else {
                    $this->logger->warning('Orden no lista para envío o no existe: ' . $orderId);
                }
            }

        } catch (\Exception $e) {
            $this->logger->error('Error en el cron de envíos: ' . $e->getMessage());
        }
    }
}
