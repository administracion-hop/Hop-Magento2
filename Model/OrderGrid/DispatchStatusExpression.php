<?php

declare(strict_types=1);

namespace Hop\Envios\Model\OrderGrid;

use Hop\Envios\Model\DispatchError;
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
 * "Parcial" es el multibulto que Hop aceptó a medias: el envío ya existe allá con los bultos
 * aceptados y Hop no admite sumarle los rechazados, así que no es despachado (le faltan bultos)
 * pero tampoco está pendiente, porque no hay nada que reintentar desde Magento.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\OrderGrid
 */
class DispatchStatusExpression extends \Zend_Db_Expr
{
    public const DISPATCHED = 'dispatched';
    public const PARTIAL = 'partial';
    public const EXISTS_IN_HOP = 'exists_in_hop';
    public const FAILED = 'failed';
    public const PENDING = 'pending';
    public const NOT_APPLICABLE = 'na';

    /**
     * Requiere el alias `sales_order` en el SELECT (el mainTableName del agregador).
     *
     * Los WHEN se evalúan en orden y gana el primero que se cumple: No aplica, Despachado,
     * Parcial, Ya existe en Hop, Error al despachar, No despachado. Los dos fallidos miran
     * status_shipment = 'failed' y no sólo el código: el reintento por credenciales borra el
     * código antes de intentar (sigue en failed, "Error al despachar"), y un pedido en
     * processing con un error viejo todavía no falló de nuevo ("No despachado").
     *
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $table = $resource->getTableName('hop_envios');
        $exists = static function (string $condition) use ($table): string {
            return "EXISTS (SELECT 1 FROM {$table} AS hop_envios"
                . " WHERE hop_envios.order_id = sales_order.entity_id AND {$condition})";
        };

        parent::__construct(sprintf(
            "CASE"
            . " WHEN sales_order.shipping_method IS NULL OR sales_order.shipping_method <> 'hop_hop'"
            . " THEN '%s'"
            . " WHEN %s THEN '%s'"
            . " WHEN %s THEN '%s'"
            . " WHEN %s THEN '%s'"
            . " WHEN %s THEN '%s'"
            . " ELSE '%s'"
            . " END",
            self::NOT_APPLICABLE,
            $exists("hop_envios.status_shipment = 'completed'"),
            self::DISPATCHED,
            $exists(sprintf("hop_envios.last_error_code = '%s'", DispatchError::CODE_MULTIBULTO_PARTIAL)),
            self::PARTIAL,
            $exists(sprintf(
                "hop_envios.status_shipment = 'failed' AND hop_envios.last_error_code = '%s'",
                DispatchError::CODE_REFERENCE_IN_USE
            )),
            self::EXISTS_IN_HOP,
            $exists("hop_envios.status_shipment = 'failed'"),
            self::FAILED,
            self::PENDING
        ));
    }
}
