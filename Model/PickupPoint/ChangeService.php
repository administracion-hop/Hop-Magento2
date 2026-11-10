<?php

namespace Hop\Envios\Model\PickupPoint;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Helper\ShippingMethod;
use Hop\Envios\Model\OrderPickupPointRepository;
use Hop\Envios\Model\Shipping\HopPriceCalculator;
use Hop\Envios\Model\Shipping\OrderPackageBuilder;
use Hop\Envios\Model\Webservice;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Changes the Hop pickup point of an order on behalf of its buyer (My Orders).
 *
 * Every rule is enforced here, server side, regardless of what the page showed: ownership,
 * eligibility, the point being one Hop offers for the order's destination, and the new point
 * costing what the buyer was charged (unless shipping was free). The point's data is taken
 * from Hop's own list, never from the request.
 */
class ChangeService
{
    const CODE_OK = 'ok';
    const CODE_NOT_FOUND = 'not_found';
    const CODE_NOT_ELIGIBLE = 'not_eligible';
    const CODE_DISPATCHED = 'dispatched';
    const CODE_INVALID_POINT = 'invalid_point';
    const CODE_SAME_POINT = 'same_point';
    const CODE_PRICE_MISMATCH = 'price_mismatch';
    const CODE_QUOTE_ERROR = 'quote_error';

    /**
     * @var ChangeEligibility
     */
    protected $eligibility;

    /**
     * @var DispatchStatus
     */
    protected $dispatchStatus;

    /**
     * @var OrderPickupPointRepository
     */
    protected $orderPickupPointRepository;

    /**
     * @var OrderPackageBuilder
     */
    protected $packageBuilder;

    /**
     * @var HopPriceCalculator
     */
    protected $priceCalculator;

    /**
     * @var ChangePriceRule
     */
    protected $priceRule;

    /**
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var ShippingMethod
     */
    protected $shippingMethod;

    /**
     * @var ScheduleFormatter
     */
    protected $scheduleFormatter;

    /**
     * @var OrderStatusHistoryRepositoryInterface
     */
    protected $historyRepository;

    /**
     * @var HopHelper
     */
    protected $helper;

    /**
     * @param ChangeEligibility $eligibility
     * @param DispatchStatus $dispatchStatus
     * @param OrderPickupPointRepository $orderPickupPointRepository
     * @param OrderPackageBuilder $packageBuilder
     * @param HopPriceCalculator $priceCalculator
     * @param ChangePriceRule $priceRule
     * @param Webservice $webservice
     * @param ShippingMethod $shippingMethod
     * @param ScheduleFormatter $scheduleFormatter
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param HopHelper $helper
     */
    public function __construct(
        ChangeEligibility $eligibility,
        DispatchStatus $dispatchStatus,
        OrderPickupPointRepository $orderPickupPointRepository,
        OrderPackageBuilder $packageBuilder,
        HopPriceCalculator $priceCalculator,
        ChangePriceRule $priceRule,
        Webservice $webservice,
        ShippingMethod $shippingMethod,
        ScheduleFormatter $scheduleFormatter,
        OrderStatusHistoryRepositoryInterface $historyRepository,
        HopHelper $helper
    ) {
        $this->eligibility = $eligibility;
        $this->dispatchStatus = $dispatchStatus;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        $this->packageBuilder = $packageBuilder;
        $this->priceCalculator = $priceCalculator;
        $this->priceRule = $priceRule;
        $this->webservice = $webservice;
        $this->shippingMethod = $shippingMethod;
        $this->scheduleFormatter = $scheduleFormatter;
        $this->historyRepository = $historyRepository;
        $this->helper = $helper;
    }

    /**
     * @param Order $order
     * @param string $pointId
     * @param int $customerId
     * @return array ['code' => string, 'message' => string, 'quoted' => bool whether Hop was quoted]
     */
    public function change(Order $order, string $pointId, int $customerId): array
    {
        $quoted = false;
        $eligibility = $this->eligibility->check($order, $customerId);
        if ($eligibility === ChangeEligibility::NOT_OWNER) {
            return $this->result(self::CODE_NOT_FOUND);
        }
        if ($eligibility === ChangeEligibility::DISPATCHED) {
            return $this->result(self::CODE_DISPATCHED);
        }
        if ($eligibility !== ChangeEligibility::OK) {
            return $this->result(self::CODE_NOT_ELIGIBLE);
        }

        $point = $this->findPoint($order, $pointId);
        if (!$point) {
            return $this->result(self::CODE_INVALID_POINT);
        }

        $currentPointId = $this->getCurrentPointId($order);
        if ($currentPointId !== null && $currentPointId === (string)$point['id']) {
            return $this->result(self::CODE_SAME_POINT);
        }

        if (!$this->priceRule->isFreeShipping($order)) {
            $quotedPrice = $this->priceCalculator->quote(
                $this->packageBuilder->build($order),
                (string)$this->packageBuilder->getDestZipCode($order),
                (string)$point['id'],
                $order->getStoreId()
            );
            $quoted = true;
            if ($quotedPrice === null) {
                return $this->result(self::CODE_QUOTE_ERROR, $quoted);
            }
            if (!$this->priceRule->matchesTariff($quotedPrice, $order)) {
                $this->helper->log(sprintf(
                    'Cambio de punto rechazado (pedido %s, punto %s): cotización %s, tarifa del checkout %s',
                    $order->getIncrementId(),
                    $point['id'],
                    $quotedPrice,
                    $this->priceRule->getTariffAmount($order)
                ));
                return $this->result(self::CODE_PRICE_MISMATCH, $quoted);
            }
        }

        // The cron (every minute) or an admin may have started the dispatch while the buyer was
        // choosing and Hop was quoting: check again right before writing.
        if ($this->dispatchStatus->hasDispatchStarted((int)$order->getId())) {
            return $this->result(self::CODE_DISPATCHED, $quoted);
        }

        $previousDescription = (string)$order->getShippingDescription();
        $this->shippingMethod->addHopData((int)$order->getId(), $this->buildHopData($point));
        $this->addHistoryComment((int)$order->getId(), $previousDescription, $currentPointId, $point);

        return $this->result(self::CODE_OK, $quoted);
    }

    /**
     * Pickup points Hop offers for the order's destination, as the checkout lists them.
     *
     * @param Order $order
     * @return array[]
     */
    public function getPoints(Order $order): array
    {
        $zipCode = $this->packageBuilder->getDestZipCode($order);
        if (!$zipCode) {
            return [];
        }

        $this->webservice->setStoreId($order->getStoreId());
        $response = $this->webservice->getPickupPoints($zipCode, $this->packageBuilder->getCountryCode($order));
        if (!is_object($response) || empty($response->data)) {
            return [];
        }

        return json_decode(json_encode($response->data), true) ?: [];
    }

    /**
     * @param Order $order
     * @return string|null
     */
    public function getCurrentPointId(Order $order): ?string
    {
        $orderPickupPoint = $this->orderPickupPointRepository->getByOrderId((int)$order->getId());
        if (!$orderPickupPoint || !$orderPickupPoint->getPickupPointId()) {
            return null;
        }
        return (string)$orderPickupPoint->getPickupPointId();
    }

    /**
     * @param string $code
     * @param bool $quoted
     * @return array
     */
    protected function result(string $code, bool $quoted = false): array
    {
        return ['code' => $code, 'message' => $this->getMessage($code), 'quoted' => $quoted];
    }

    /**
     * Message the buyer sees for a result code, also used by the points listing.
     *
     * @param string $code One of the CODE_* constants
     * @return string
     */
    public function getMessage(string $code): string
    {
        $messages = [
            self::CODE_OK => __('Listo, cambiamos el punto de retiro de tu pedido.'),
            self::CODE_NOT_FOUND => __('No encontramos el pedido.'),
            self::CODE_NOT_ELIGIBLE => __('Este pedido no permite cambiar el punto de retiro.'),
            self::CODE_DISPATCHED => __('Tu pedido ya fue despachado y no se puede cambiar el punto de retiro.'),
            self::CODE_INVALID_POINT => __('El punto elegido no está disponible para tu dirección. Elegí otro punto.'),
            self::CODE_SAME_POINT => __('Ese ya es el punto de retiro de tu pedido.'),
            self::CODE_PRICE_MISMATCH => __('Este punto tiene otro costo de envío. Podés elegir otro punto o contactar a atención al cliente.'),
            self::CODE_QUOTE_ERROR => __('No pudimos confirmar el cambio en este momento. Intentá de nuevo más tarde o contactá a atención al cliente.'),
        ];

        return (string)$messages[$code];
    }

    /**
     * @param Order $order
     * @param string $pointId
     * @return array|null
     */
    protected function findPoint(Order $order, string $pointId): ?array
    {
        if ($pointId === '') {
            return null;
        }
        foreach ($this->getPoints($order) as $point) {
            if (isset($point['id']) && (string)$point['id'] === $pointId) {
                return $point;
            }
        }
        return null;
    }

    /**
     * Same keys the checkout and the admin send to ShippingMethod::addHopData().
     *
     * @param array $point
     * @return array
     */
    protected function buildHopData(array $point): array
    {
        return [
            'hopPointId' => (string)$point['id'],
            'hopPointReferenceName' => (string)($point['reference_name'] ?? ''),
            'hopPointAddress' => (string)($point['full_address'] ?? ''),
            'hopPointSchedules' => $this->scheduleFormatter->format($point['schedules'] ?? []),
            'hopPointPostcode' => (string)($point['zip_code'] ?? ''),
            'hopPointName' => (string)($point['name'] ?? ''),
            'hopPointSeller' => (string)($point['seller'] ?? ''),
            'hopPointProvidercode' => (string)($point['provider_code'] ?? ''),
            'hopPointDistributorId' => (string)($point['distributor_id'] ?? ''),
            'hopPointAgencycode' => (string)($point['agency_code'] ?? ''),
        ];
    }

    /**
     * Saves only the comment row: addHopData() already saved the order, and saving it again
     * would run every order-save observer a second time. The order is read again just for its
     * new shipping description.
     *
     * The point is already changed when this runs: if the comment can't be saved, the error is
     * logged and the change is still reported as done, since it is.
     *
     * @param int $orderId
     * @param string $previousDescription
     * @param string|null $previousPointId
     * @param array $newPoint
     * @return void
     */
    protected function addHistoryComment(int $orderId, string $previousDescription, ?string $previousPointId, array $newPoint)
    {
        $order = $this->shippingMethod->getOrder($orderId);
        $history = $order->addCommentToStatusHistory(
            __(
                'Punto Hop cambiado por el comprador desde Mis pedidos. Anterior: %1 (ID %2). Nuevo: %3 (ID %4).',
                $previousDescription ?: '-',
                $previousPointId ?: '-',
                $order->getShippingDescription(),
                $newPoint['id']
            ),
            false,
            false
        );
        try {
            $this->historyRepository->save($history);
        } catch (\Exception $e) {
            $this->helper->log(sprintf(
                'Cambio de punto guardado sin comentario en el historial (pedido %s, punto %s): %s',
                $order->getIncrementId(),
                $newPoint['id'],
                $e->getMessage()
            ), true);
        }
    }
}
