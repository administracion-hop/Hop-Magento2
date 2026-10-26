<?php

declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\Order;

use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;

/**
 * Despacho masivo a Hop desde la grilla de pedidos.
 *
 * Reusa ShippingMethod::createShipmentData() (vía retryDispatch) para que el resultado sea
 * exactamente el mismo que el del botón "Enviar a HOP" del pedido. Un pedido que falla no
 * corta el lote: cada uno se informa en el resumen.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Controller\Adminhtml\Order
 */
class MassDispatch extends Action implements HttpPostActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Hop_Envios::order_dispatch';

    /**
     * Tope de pedidos por lote. Cada despacho es una llamada a la API de Hop: sin tope, un
     * "Select All" sobre la grilla entera muere por max_execution_time con medio lote ya
     * despachado y sin resumen que lo diga.
     */
    private const MAX_ORDERS = 100;

    /**
     * @var Filter
     */
    private Filter $filter;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var ShippingMethod
     */
    private ShippingMethod $shippingMethodHelper;

    /**
     * @var HopEnviosRepository
     */
    private HopEnviosRepository $hopEnviosRepository;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Context $context
     * @param Filter $filter
     * @param CollectionFactory $collectionFactory
     * @param ShippingMethod $shippingMethodHelper
     * @param HopEnviosRepository $hopEnviosRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        ShippingMethod $shippingMethodHelper,
        HopEnviosRepository $hopEnviosRepository,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->shippingMethodHelper = $shippingMethodHelper;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->logger = $logger;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('sales/order/index');

        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $resultRedirect;
        }

        if ($collection->getSize() > self::MAX_ORDERS) {
            $this->messageManager->addErrorMessage(
                __(
                    'Seleccionaste %1 pedidos y el máximo por lote es %2. Filtrá la grilla y despachá en tandas.',
                    $collection->getSize(),
                    self::MAX_ORDERS
                )
            );
            return $resultRedirect;
        }

        $dispatched = [];
        $skipped = [];
        $failed = [];

        // Webservice informa cada fallo con su propio mensaje, sin número de pedido: en un lote
        // son líneas sueltas que repiten lo que ya dice el resumen (el mismo texto queda en
        // last_error). Se apartan los mensajes previos, se descartan los del lote y se devuelven.
        $previousMessages = $this->messageManager->getMessages(true)->getItems();

        /** @var Order $order */
        foreach ($collection as $order) {
            $increment = (string)$order->getIncrementId();
            $hopEnvios = $this->hopEnviosRepository->getByOrderId((int)$order->getId());
            $reason = $this->skipReason($order, $hopEnvios);

            if ($reason !== null) {
                $skipped[] = $increment . ' (' . $reason . ')';
                continue;
            }

            try {
                if ($this->shippingMethodHelper->retryDispatch($order)) {
                    $dispatched[] = $increment;
                } else {
                    $failed[] = $increment . ' (' . $this->lastError((int)$order->getId()) . ')';
                }
            } catch (\Exception $e) {
                // Un pedido roto no puede frenar el lote: se registra y se sigue.
                $this->logger->error('Error en despacho masivo a Hop', [
                    'order_id' => $order->getId(),
                    'increment_id' => $increment,
                    'error' => $e->getMessage()
                ]);
                $failed[] = $increment . ' (' . $e->getMessage() . ')';
            }
        }

        $this->messageManager->getMessages(true);
        if ($previousMessages) {
            $this->messageManager->addMessages($previousMessages);
        }

        if ($dispatched) {
            $this->messageManager->addSuccessMessage(
                __('%1 pedido(s) despachado(s) a Hop: %2', count($dispatched), implode(', ', $dispatched))
            );
        }
        if ($skipped) {
            $this->messageManager->addNoticeMessage(
                __('%1 pedido(s) omitido(s): %2', count($skipped), implode(', ', $skipped))
            );
        }
        if ($failed) {
            $this->messageManager->addErrorMessage(
                __('%1 pedido(s) con error: %2', count($failed), implode(', ', $failed))
            );
        }
        if (!$dispatched && !$skipped && !$failed) {
            $this->messageManager->addNoticeMessage(__('No hay pedidos para despachar a Hop.'));
        }

        return $resultRedirect;
    }

    /**
     * Motivo por el que el pedido no se despacha, o null si hay que despacharlo.
     *
     * @param Order $order
     * @param \Hop\Envios\Model\HopEnvios|null $hopEnvios Registro Hop del pedido, si existe
     * @return string|null
     */
    private function skipReason(Order $order, $hopEnvios): ?string
    {
        if ($order->getShippingMethod() !== 'hop_hop') {
            return (string)__('no es un envío Hop');
        }

        if (in_array($order->getState(), [Order::STATE_CANCELED, Order::STATE_CLOSED], true)) {
            return (string)__('pedido %1', $order->getState());
        }

        if ($hopEnvios && $hopEnvios->getStatusShipment() === 'completed') {
            return (string)__('ya despachado');
        }

        // Multibulto con bultos rechazados: el envío ya existe en Hop y no admite sumar bultos,
        // así que reintentar no manda nada. Mismo criterio que oculta "Enviar a HOP" en el pedido.
        if ($hopEnvios && !DispatchError::isRetryable($hopEnvios->getLastErrorCode())) {
            return (string)__('ya creado en Hop con bultos rechazados');
        }

        // Con envíos parciales el despacho lo hace SalesOrderShipmentSaveAfter al completar el
        // último envío (es el único que conoce los bultos). Mismo criterio que Order\Send.
        if ($order->hasShipments() && $order->canShip()) {
            return (string)__('tiene productos sin enviar en Magento');
        }

        return null;
    }

    /**
     * Se relee después del intento: el error lo escribe markFailed() durante el despacho, así
     * que el registro que teníamos antes del intento todavía no lo tiene.
     *
     * @param int $orderId
     * @return string
     */
    private function lastError(int $orderId): string
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);
        return $hopEnvios && $hopEnvios->getLastError()
            ? (string)$hopEnvios->getLastError()
            : (string)__('error al enviar a Hop');
    }
}
