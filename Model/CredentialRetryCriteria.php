<?php

namespace Hop\Envios\Model;

use Hop\Envios\Cron\GenarateShipment;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;

/**
 * Qué despachos fallidos por credenciales se reintentan solos. Un solo criterio para el parche de
 * pedidos viejos (Setup/Patch/Data/ClassifyCredentialFailures), la reprogramación al recuperarse
 * las credenciales (HopEnviosRepository::scheduleCredentialRetries) y el corte al reintentar
 * (Cron/GenarateShipment::retryOrder).
 *
 * Se excluyen los pedidos cancelados, cerrados o retenidos, y los de hace más de 30 días.
 *
 * Los completos se reintentan en el flujo normal: Magento pasa a completo un pedido facturado en
 * cuanto se guarda su envío de Magento, y el despacho a Hop corre justo después de ese guardado,
 * así que un fallo nuevo por credenciales suele quedar en un pedido completo. En cambio, el parche
 * de pedidos viejos (fallos de antes de esta versión) también excluye los completos: un pedido de
 * hace semanas pudo haberse resuelto por fuera de Hop sin cancelarse en Magento, no tiene envío en
 * Hop con su referencia, y el reintento crearía un envío real y cobrable.
 */
class CredentialRetryCriteria
{
    const EXCLUDED_STATES = [
        Order::STATE_CANCELED,
        Order::STATE_CLOSED,
        Order::STATE_HOLDED,
    ];

    /** Para el parche de pedidos viejos: además, los completos. */
    const EXCLUDED_STATES_HISTORICAL = [
        Order::STATE_CANCELED,
        Order::STATE_CLOSED,
        Order::STATE_HOLDED,
        Order::STATE_COMPLETE,
    ];

    const MAX_AGE_DAYS = 30;

    /**
     * Suma el criterio a una consulta sobre hop_envios (alias h) unida a sales_order (alias o).
     *
     * @param Select $select
     * @param int|null $now timestamp, para las pruebas
     * @param bool $historical true para el parche de pedidos viejos (excluye también los completos)
     * @return Select
     */
    public static function apply(Select $select, $now = null, $historical = false)
    {
        return $select
            ->where('h.status_shipment = ?', GenarateShipment::SHIPMENT_STATUS_FAILED)
            ->where('o.state NOT IN (?)', $historical ? self::EXCLUDED_STATES_HISTORICAL : self::EXCLUDED_STATES)
            ->where('o.created_at >= ?', self::cutoff($now));
    }

    /**
     * Si el pedido todavía se puede reintentar solo (se vuelve a mirar al reintentar: el estado
     * puede haber cambiado desde que se reprogramó).
     *
     * @param Order $order
     * @param int|null $now
     * @return bool
     */
    public static function isOrderEligible($order, $now = null)
    {
        return $order->getId()
            && !in_array($order->getState(), self::EXCLUDED_STATES, true)
            && (string)$order->getCreatedAt() >= self::cutoff($now);
    }

    /**
     * @param int|null $now
     * @return string fecha UTC, como created_at de sales_order
     */
    public static function cutoff($now = null)
    {
        return gmdate('Y-m-d H:i:s', ($now ?? time()) - self::MAX_AGE_DAYS * 86400);
    }
}
