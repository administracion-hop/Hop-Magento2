<?php

namespace Hop\Envios\Controller\Order;

use Hop\Envios\Model\PickupPoint\ChangeEligibility;
use Hop\Envios\Model\PickupPoint\ChangeService;
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
        $eligibility = $order ? $this->eligibility->check($order, $this->getCustomerId()) : ChangeEligibility::NOT_OWNER;
        if ($eligibility !== ChangeEligibility::OK) {
            // Someone else's order answers exactly like a nonexistent one; the buyer's own order
            // says why (dispatched may happen after the page was rendered).
            $code = [
                ChangeEligibility::NOT_OWNER => ChangeService::CODE_NOT_FOUND,
                ChangeEligibility::DISPATCHED => ChangeService::CODE_DISPATCHED,
            ][$eligibility] ?? ChangeService::CODE_NOT_ELIGIBLE;
            return $this->json([
                'success' => false,
                'code' => $code,
                'message' => $this->changeService->getMessage($code),
                'points' => [],
            ]);
        }

        return $this->json([
            'success' => true,
            'currentPointId' => $this->changeService->getCurrentPointId($order),
            'points' => $this->changeService->getPoints($order),
        ]);
    }
}
