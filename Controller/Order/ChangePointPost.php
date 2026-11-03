<?php

namespace Hop\Envios\Controller\Order;

use Hop\Envios\Model\PickupPoint\ChangeService;
use Magento\Framework\App\Action\HttpPostActionInterface;

/**
 * POST hop/order/changepointpost (order_id, point_id, form_key) — confirms the buyer's new
 * pickup point. Magento validates the form_key of every frontend POST before this runs.
 */
class ChangePointPost extends AbstractChangePoint implements HttpPostActionInterface
{
    /**
     * Each confirmation may quote Hop once: cap them per order and session.
     */
    const MAX_ATTEMPTS = 10;

    const SESSION_ATTEMPTS_KEY = 'hop_change_point_attempts';

    /**
     * Results that went as far as quoting Hop, the only ones that spend an attempt. Rejections
     * decided before the quote (same point, a point Hop doesn't offer, already dispatched...) don't.
     */
    const QUOTED_CODES = [
        ChangeService::CODE_OK,
        ChangeService::CODE_PRICE_MISMATCH,
        ChangeService::CODE_QUOTE_ERROR,
    ];

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->json([
                'success' => false,
                'code' => 'login_required',
                'message' => (string)__('Tu sesión expiró. Iniciá sesión para continuar.'),
            ]);
        }

        $order = $this->getOrder();
        if (!$order) {
            return $this->json([
                'success' => false,
                'code' => ChangeService::CODE_NOT_FOUND,
                'message' => (string)__('No encontramos el pedido.'),
            ]);
        }

        $orderId = (int)$order->getId();
        if ($this->getAttempts($orderId) >= self::MAX_ATTEMPTS) {
            return $this->json([
                'success' => false,
                'code' => 'too_many_attempts',
                'message' => (string)__('Hiciste demasiados intentos. Contactá a atención al cliente para cambiar el punto de retiro.'),
            ]);
        }

        $result = $this->changeService->change(
            $order,
            trim((string)$this->request->getParam('point_id')),
            $this->getCustomerId()
        );
        if (in_array($result['code'], self::QUOTED_CODES, true)) {
            $this->registerAttempt($orderId);
        }

        if ($result['code'] === ChangeService::CODE_OK) {
            $this->messageManager->addSuccessMessage($result['message']);
        }

        return $this->json(['success' => $result['code'] === ChangeService::CODE_OK] + $result);
    }

    /**
     * @param int $orderId
     * @return int attempts that quoted Hop for this order in this session
     */
    private function getAttempts(int $orderId): int
    {
        $attempts = (array)$this->customerSession->getData(self::SESSION_ATTEMPTS_KEY);
        return (int)($attempts[$orderId] ?? 0);
    }

    /**
     * @param int $orderId
     * @return void
     */
    private function registerAttempt(int $orderId): void
    {
        $attempts = (array)$this->customerSession->getData(self::SESSION_ATTEMPTS_KEY);
        $attempts[$orderId] = (int)($attempts[$orderId] ?? 0) + 1;
        $this->customerSession->setData(self::SESSION_ATTEMPTS_KEY, $attempts);
    }
}
