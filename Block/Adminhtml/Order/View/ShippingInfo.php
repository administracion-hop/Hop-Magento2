<?php
namespace Hop\Envios\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\RequestInterface;
use Hop\Envios\Model\OrderPickupPointRepository;

class ShippingInfo extends Template
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Sales\Model\Order|null
     */
    protected $order;

    /**
     * @var OrderPickupPointRepository
     */
    protected $orderPickupPointRepository;

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RequestInterface $request
     * @param OrderPickupPointRepository $orderPickupPointRepository
     * @param array $data
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RequestInterface $request,
        OrderPickupPointRepository $orderPickupPointRepository,
        array $data = []
    ) {
        $this->orderRepository = $orderRepository;
        $this->request = $request;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        parent::__construct($context, $data);
    }

    /**
     * Get current order
     *
     * @return \Magento\Sales\Model\Order|null
     */
    public function getOrder()
    {
        if ($this->order === null) {
            $orderId = $this->request->getParam('order_id');
            if ($orderId) {
                try {
                    $this->order = $this->orderRepository->get($orderId);
                } catch (\Exception $e) {
                    $this->order = null;
                }
            }
        }
        return $this->order;
    }

    /**
     * @return array
     */
    public function getSelectedPickupPointData()
    {
        $order = $this->getOrder();
        if (!$order) {
            return [];
        }
        $selectedPickupPoint = $this->orderPickupPointRepository->getByOrderId((int)$order->getId());
        if (!$selectedPickupPoint) {
            return [];
        }

        $info = [
            'pickup_point_id'=> $selectedPickupPoint->getData('pickup_point_id'),
            'original_pickup_point_id' => $selectedPickupPoint->getData('original_pickup_point_id'),
            'original_shipping_description' => $selectedPickupPoint->getData('original_shipping_description'),
        ];
        return array_filter($info);
    }


}
