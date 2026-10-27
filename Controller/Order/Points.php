<?php

namespace Hop\Envios\Controller\Order;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Magento\Framework\App\Action\HttpGetActionInterface;

/**
 * GET hop/order/points/order_id/N — the pickup points Hop offers for the order's destination
 * and the order's current point. Lists only: never quotes a price.
 */
class Points extends AbstractChangePoint implements HttpGetActionInterface
{
    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->json(['success' => false, 'code' => 'login_required', 'points' => []]);
        }

        $order = $this->getOrder();
        if (!$order || $this->eligibility->check($order, $this->getCustomerId()) !== ChangeEligibility::OK) {
            return $this->json(['success' => false, 'code' => 'not_found', 'points' => []]);
        }

        return $this->json([
            'success' => true,
            'currentPointId' => $this->changeService->getCurrentPointId($order),
            'points' => $this->changeService->getPoints($order),
        ]);
    }
}
