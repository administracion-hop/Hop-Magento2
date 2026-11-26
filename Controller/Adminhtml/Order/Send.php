<?php
namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Hop\Envios\Logger\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Framework\Message\MessageInterface;

class Send extends \Magento\Backend\App\Action
{
    /**
     * @var RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var ShippingMethod
     */
    protected $shippingMethodHelper;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * Constructor
     */
    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        ShippingMethod $shippingMethodHelper,
        HopEnviosRepository $hopEnviosRepository
    ) {
        parent::__construct($context);
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->shippingMethodHelper = $shippingMethodHelper;
        $this->hopEnviosRepository = $hopEnviosRepository;
    }

    /**
     * Execute custom action
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function execute()
    {
        try {
            $orderId = (int)$this->getRequest()->getParam('order_id');

            if (!$orderId) {
                $this->messageManager->addErrorMessage(__('Error: ID de orden requerido.'));
                return $this->redirectBack(null);
            }


            $order = $this->loadOrder($orderId);
            if (!$order || !$order->getId()) {
                $this->messageManager->addErrorMessage(__('Error: Orden no encontrada.'));
                return $this->redirectBack(null);
            }


            // Con envíos parciales el despacho lo hace el observer al completar el último envío
            // (es el único que conoce los bultos); acá no hay nada que reintentar todavía.
            if ($order->hasShipments() && $order->canShip()) {
                $this->messageManager->addErrorMessage(
                    __('El pedido tiene productos sin enviar en Magento. Terminá de crear los envíos: al completar el último se despacha a Hop.')
                );
                return $this->redirectBack($orderId);
            }

            $errorsBefore = $this->countErrorMessages();
            if ($this->shippingMethodHelper->retryDispatch($order)) {
                $this->messageManager->addSuccessMessage(
                    __('Orden enviada a Hop correctamente.')
                );

            } elseif ($this->countErrorMessages() === $errorsBefore && ($reason = $this->lastError($orderId))) {
                // El despacho no llamó a Hop (por ejemplo, los envíos ya se habían mandado todos y
                // se registró lo que había pasado), así que Webservice no dejó ningún aviso: se
                // muestra el motivo guardado.
                $this->messageManager->addErrorMessage(
                    __('Error al enviar la orden a Hop: %1', $reason)
                );
            } else {
                $this->messageManager->addErrorMessage(
                    __('Error al enviar la orden a Hop')
                );
            }
            return $this->redirectBack($orderId);
        } catch (\Exception $e) {
            $this->logger->error('Error al enviar a Hop', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_id' => $orderId ?? null
            ]);

            $this->messageManager->addErrorMessage(
                __('Error al enviar a Hop: %1', $e->getMessage())
            );
        }

        return $this->redirectBack(null);
    }

    /**
     * @return int avisos de error pendientes de mostrar
     */
    private function countErrorMessages()
    {
        return (int)$this->messageManager->getMessages(false)->getCountByType(MessageInterface::TYPE_ERROR);
    }

    /**
     * Motivo del último fallo, releído después del intento (lo escribe markFailed()).
     *
     * @param int $orderId
     * @return string
     */
    private function lastError($orderId)
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId((int)$orderId);
        return $hopEnvios ? (string)$hopEnvios->getLastError() : '';
    }

    /**
     * Load order by ID
     *
     * @param int $orderId
     * @return \Magento\Sales\Model\Order|null
     */
    private function loadOrder($orderId)
    {
        try {
            return $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            $this->logger->error('Error loading order: ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Redirect back to the referring page
     *
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function redirectBack($orderId)
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if ($orderId) {
            $orderUrl = $this->_backendUrl->getUrl('sales/order/view', ['order_id' => $orderId]);
            return $resultRedirect->setUrl($orderUrl);
        }

        // Fallback: redirigir al dashboard de órdenes
        return $resultRedirect->setPath('sales/order/index');
    }
}
