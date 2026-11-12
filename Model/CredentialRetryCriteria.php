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
 * Se excluyen los pedidos que ya no esperan un despacho (cancelados, cerrados, completos o
 * retenidos) y los de hace más de 30 días: un pedido resuelto por fuera de Hop y no cancelado en
 * Magento no tiene envío en Hop con su referencia, así que el reintento crearía un envío real
 * y cobrable para un pedido ya entregado.
 */
class CredentialRetryCriteria
{
    const EXCLUDED_STATES = [
        Order::STATE_CANCELED,
        Order::STATE_CLOSED,
        Order::STATE_COMPLETE,
        Order::STATE_HOLDED,
    ];

    const MAX_AGE_DAYS = 30;

    /**
     * Suma el criterio a una consulta sobre hop_envios (alias h) unida a sales_order (alias o).
     *
     * @param Select $select
     * @param int|null $now timestamp, para las pruebas
     * @return Select
     */
    public static function apply(Select $select, $now = null)
    {
        return $select
            ->where('h.status_shipment = ?', GenarateShipment::SHIPMENT_STATUS_FAILED)
            ->where('o.state NOT IN (?)', self::EXCLUDED_STATES)
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
