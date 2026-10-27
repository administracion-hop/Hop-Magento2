<?php

declare(strict_types=1);

namespace Hop\Envios\Model\OrderGrid;

use Magento\Framework\App\ResourceConnection;

/**
 * Expresión SQL que resuelve el estado de despacho Hop de un pedido para sales_order_grid.
 *
 * Se inyecta como columna del agregador de la grilla (etc/di.xml): Magento envuelve cada
 * columna en Zend_Db_Expr, así que un CASE entra sin tocar nada del core. El mismo SQL lo
 * reusa el patch de backfill, por eso vive acá y no inline en el XML.
 *
 * Usa EXISTS y no un JOIN a propósito. El agregador corre en cada guardado de pedido, Hop o
 * no, así que la consulta tiene que cortar en el primer match (índice HOP_ENVIOS_ORDER_ID) en
 * lugar de materializar la fila. Además hop_envios no tiene unique por order_id: si alguna vez
 * quedan dos filas para el mismo pedido, un JOIN devolvería una cualquiera y el estado sería no
 * determinístico; con EXISTS alcanza con que una esté completada.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\OrderGrid
 */
class DispatchStatusExpression extends \Zend_Db_Expr
{
    public const DISPATCHED = 'dispatched';
    public const PENDING = 'pending';
    public const NOT_APPLICABLE = 'na';

    /**
     * Requiere el alias `sales_order` en el SELECT (el mainTableName del agregador).
     *
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        parent::__construct(sprintf(
            "CASE"
            . " WHEN sales_order.shipping_method IS NULL OR sales_order.shipping_method <> 'hop_hop'"
            . " THEN '%s'"
            . " WHEN EXISTS ("
            . "SELECT 1 FROM %s AS hop_envios"
            . " WHERE hop_envios.order_id = sales_order.entity_id"
            . " AND hop_envios.status_shipment = 'completed'"
            . ")"
            . " THEN '%s'"
            . " ELSE '%s'"
            . " END",
            self::NOT_APPLICABLE,
            $resource->getTableName('hop_envios'),
            self::DISPATCHED,
            self::PENDING
        ));
    }
}
