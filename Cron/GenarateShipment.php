<?php

namespace Hop\Envios\Cron;

use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Framework\DB\TransactionFactory;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\ShipmentNotifier;
use Magento\Sales\Model\Order;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Model\CredentialRetryCriteria;
use Hop\Envios\Model\Health\CredentialRetryScheduler;
use Hop\Envios\Model\DispatchError;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

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
     * @var TransactionFactory
     */
    protected $transactionFactory;

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

    /**
     * @var ShippingMethod
     */
    protected $shippingMethodHelper;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    protected $orderStatusHistoryRepository;

    /**
     * @var CredentialRetryScheduler
     */
    protected $credentialRetryScheduler;

    const SHIPMENT_STATUS_PENDING = 'pending';
    const SHIPMENT_STATUS_PROCESING = 'processing';
    const SHIPMENT_STATUS_COMPLETED = 'completed';
    /** Hop rechazó el despacho. Con next_retry_at se reintenta solo; sin él, espera revisión manual. */
    const SHIPMENT_STATUS_FAILED = 'failed';

    public function __construct(
        OrderFactory $orderFactory,
        ShipmentFactory $shipmentFactory,
        TransactionFactory $transactionFactory,
        LoggerInterface $logger,
        ShipmentNotifier $shipmentNotifier,
        HopEnviosRepository $hopEnviosRepository,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        ShippingMethod $shippingMethodHelper,
        OrderStatusHistoryRepositoryInterface $orderStatusHistoryRepository,
        CredentialRetryScheduler $credentialRetryScheduler
    ) {
        $this->orderFactory = $orderFactory;
        $this->shipmentFactory = $shipmentFactory;
        $this->transactionFactory = $transactionFactory;
        $this->logger = $logger;
        $this->shipmentNotifier = $shipmentNotifier;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->shippingMethodHelper = $shippingMethodHelper;
        $this->orderStatusHistoryRepository = $orderStatusHistoryRepository;
        $this->credentialRetryScheduler = $credentialRetryScheduler;
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

            // Los que esperan credenciales y su cuenta ya funciona pasan a reintentarse ahora.
            try {
                $this->credentialRetryScheduler->run();
            } catch (\Exception $e) {
                $this->logger->error(__('Error reprogramando despachos por credenciales: ') . $e->getMessage());
            }

            $failedOrders = $this->hopEnviosRepository->getRetryableFailed();
            foreach ($failedOrders as $failedOrder) {
                $this->retryOrder($failedOrder);
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
     * Reintento automático de un despacho fallido cuyo next_retry_at ya venció.
     *
     * Antes de intentar se vacía next_retry_at: si el intento vuelve a fallar, markFailed()
     * agenda el siguiente escalón; si no llega a despachar nada (pedido cancelado, bultos ya
     * procesados), queda failed sin reintento y a la vista para revisión manual en vez de
     * reintentarse cada minuto.
     *
     * @param \Hop\Envios\Model\HopEnvios $hopEnvio
     */
    protected function retryOrder($hopEnvio)
    {
        $byCredentials = $hopEnvio->getLastErrorCode() === DispatchError::CODE_CREDENTIALS;
        $hopEnvio->setNextRetryAt(null);
        $this->hopEnviosRepository->save($hopEnvio);

        $order = $this->orderFactory->create()->load($hopEnvio->getOrderId());

        // Un pedido cancelado o cerrado (reembolsado) no se despacha, aunque ya tenga envío de
        // Magento: queda failed y sin reintento, a la vista para revisión manual. Los que esperan
        // credenciales, además, siguen el criterio completo (ni completos, ni retenidos, ni de
        // hace más de 30 días): el estado puede haber cambiado desde que se reprogramaron. Conservan
        // el motivo: si el pedido vuelve a cumplir el criterio (por ejemplo, se libera una
        // retención), se reprograma de nuevo.
        if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)
            || ($byCredentials && !CredentialRetryCriteria::isOrderEligible($order))
        ) {
            $this->logger->info(__('Orden %1 %2: no se reintenta el despacho a Hop', $hopEnvio->getOrderId(), $order->getState()));
            return;
        }

        $this->logger->info(__('Reintento %1 de despacho a Hop para la orden %2', (int)$hopEnvio->getAttempts() + 1, $hopEnvio->getOrderId()));

        try {
            $hasShipments = $order->getId() && $this->shipmentCollectionFactory->create()
                ->addFieldToFilter('order_id', $order->getId())
                ->getSize() > 0;
            if (!$hasShipments && (!$order->getId() || !$order->canShip())) {
                // Mismo corte que processOrder(): no hay nada para despachar (por ejemplo, se
                // resolvió a mano mientras tanto). No se comenta como reintento, y sin el motivo de
                // credenciales queda para revisión manual en vez de reprogramarse cada minuto.
                $this->logger->warning(__('Orden no lista para envío o no existe: ') . $hopEnvio->getOrderId());
                if ($byCredentials) {
                    $hopEnvio->setLastErrorCode(null);
                    $this->hopEnviosRepository->save($hopEnvio);
                }
                return;
            }
            if ($byCredentials) {
                // Recién acá, a punto de intentar: si vuelve a fallar, markFailed deja el motivo
                // nuevo (de credenciales u otro).
                $hopEnvio->setLastErrorCode(null);
                $this->hopEnviosRepository->save($hopEnvio);
            }
            $before = [$hopEnvio->getStatusShipment(), $hopEnvio->getLastError(), (int)$hopEnvio->getAttempts()];
            if ($hasShipments) {
                $this->shippingMethodHelper->retryDispatch($order);
            } else {
                // Sin envío de Magento todavía: mismo camino que una orden pendiente.
                $this->processOrder($hopEnvio);
            }
        } catch (\Exception $e) {
            $this->logger->error(__('Error reintentando el despacho de la orden ') . $hopEnvio->getOrderId() . ': ' . $e->getMessage());
        }

        if ($byCredentials && $order->getId() && isset($before)) {
            $this->addCredentialsRetryComment((int)$order->getId(), $before);
        }
    }

    /**
     * Deja en el historial del pedido que se reintentó solo y con qué resultado, solo si el
     * despacho llegó a intentarse (algo cambió en hop_envios). Se relee el pedido, porque el
     * despacho lo cambia en otra instancia: el comentario tiene que llevar el estado actual. Se
     * guarda el comentario solo, sin guardar el pedido, para no disparar los observers.
     *
     * @param int $orderId
     * @param array $before [estado, último error, intentos] antes del intento
     * @return void
     */
    protected function addCredentialsRetryComment($orderId, array $before)
    {
        try {
            $hopEnvio = $this->hopEnviosRepository->getByOrderId($orderId);
            if (!$hopEnvio) {
                return;
            }
            $completed = $hopEnvio->getStatusShipment() === self::SHIPMENT_STATUS_COMPLETED;
            $after = [$hopEnvio->getStatusShipment(), $hopEnvio->getLastError(), (int)$hopEnvio->getAttempts()];
            if (!$completed && $after === $before && !$hopEnvio->getLastErrorCode()) {
                // No se llegó a intentar el despacho: no hay resultado que comentar.
                return;
            }
            $result = $completed
                ? __('Resultado: despachado a Hop.')
                : __('Resultado: falló de nuevo. %1', (string)$hopEnvio->getLastError());
            $order = $this->orderFactory->create()->load($orderId);
            $history = $order->addCommentToStatusHistory(
                __('Reintentado automáticamente al recuperarse las credenciales de Hop.') . ' ' . $result,
                false,
                false
            );
            $this->orderStatusHistoryRepository->save($history);
        } catch (\Exception $e) {
            $this->logger->error(__('No se pudo agregar el comentario de reintento a la orden %1: %2', $orderId, $e->getMessage()));
        }
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
            // Transacción nueva por orden: la compartida acumulaba los objetos de las órdenes
            // anteriores y los volvía a guardar, re-disparando su despacho a Hop.
            $this->transactionFactory->create()->addObject($shipment)
                ->addObject($order->save())
                ->save();

            $this->updateOrderStatus($order);
            $order->save();

            // Tracking + label already written above by SalesOrderShipmentSaveAfter,
            // fired synchronously by the transaction save. Just notify the customer.
            $this->shipmentNotifier->notify($shipment);

            // El observer ya dejó su resultado en hop_envios (otra instancia): releer para no
            // pisar un failed con completed ni borrar last_error con datos viejos.
            $hopEnvio = $this->hopEnviosRepository->getByOrderId((int)$order->getId()) ?: $hopEnvio;
            if ($hopEnvio->getStatusShipment() === self::SHIPMENT_STATUS_FAILED) {
                $this->logger->error(__('Hop rechazó el despacho de la orden %1: %2', $order->getId(), $hopEnvio->getLastError()));
                return;
            }
            $this->updateShipmentStatus($hopEnvio, self::SHIPMENT_STATUS_COMPLETED);
            $this->logger->info(__('Shipment generated successfully for order ID: ') . $order->getId());
        } catch (\Exception $e) {
            $hopEnvio = $this->hopEnviosRepository->getByOrderId((int)$order->getId()) ?: $hopEnvio;
            if ($hopEnvio->getStatusShipment() !== self::SHIPMENT_STATUS_FAILED) {
                $this->updateShipmentStatus($hopEnvio, self::SHIPMENT_STATUS_PENDING);
            }
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
