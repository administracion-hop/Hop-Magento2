<?php
namespace Hop\Envios\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\RedirectFactory;
use Hop\Envios\Logger\LoggerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Hop\Envios\Helper\ShippingMethod;

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
     * Constructor
     */
    public function __construct(
        Context $context,
        RedirectFactory $resultRedirectFactory,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        ShippingMethod $shippingMethodHelper,
    ) {
        parent::__construct($context);
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->shippingMethodHelper = $shippingMethodHelper;
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


            if ($this->shippingMethodHelper->createShipmentData($order)){
                $this->messageManager->addSuccessMessage(
                    __('Orden enviada a Hop correctamente.')
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
