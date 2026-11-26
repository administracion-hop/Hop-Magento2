<?php

declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Hop\Envios\Model\OrderGrid\DispatchStatusExpression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * Recalcula hop_dispatch_status en los pedidos que ya tenían un despacho fallido.
 *
 * Antes de esta versión esos pedidos figuraban "No despachado"; ahora les corresponde
 * "Ya existe en Hop" o "Error al despachar". El agregador de la grilla sólo reescribe la fila
 * cuando el pedido o su despacho cambian, así que sin esto quedarían con el valor viejo.
 *
 * Sólo toca los pedidos con una fila failed en hop_envios: el resto no cambia de valor. Mismo
 * esquema que BackfillHopDispatchStatus (tandas por entity_id, sin transacción) por los mismos
 * motivos; es idempotente, así que un corte a mitad se resuelve volviendo a correr
 * setup:upgrade.
 *
 * @version 1.0.0
 * @copyright Copyright (c) 2025 Hop Envíos
 */
class RefreshHopDispatchStatusFailures implements DataPatchInterface, NonTransactionableInterface
{
    /**
     * Pedidos por tanda (ver BackfillHopDispatchStatus::BATCH_SIZE).
     */
    private const BATCH_SIZE = 5000;

    /**
     * @var ModuleDataSetupInterface
     */
    private ModuleDataSetupInterface $moduleDataSetup;

    /**
     * @var DispatchStatusExpression
     */
    private DispatchStatusExpression $dispatchStatus;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param DispatchStatusExpression $dispatchStatus El mismo CASE que usa la grilla
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        DispatchStatusExpression $dispatchStatus
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->dispatchStatus = $dispatchStatus;
    }

    /**
     * Aplica el parche.
     *
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $gridTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order_grid'));
        $hopTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('hop_envios'));

        $sql = sprintf(
            'UPDATE %s AS grid'
            . ' INNER JOIN %s AS sales_order ON sales_order.entity_id = grid.entity_id'
            . ' SET grid.hop_dispatch_status = %s'
            . ' WHERE grid.entity_id > ? AND grid.entity_id <= ?'
            . " AND EXISTS (SELECT 1 FROM %s AS failed WHERE failed.order_id = grid.entity_id"
            . " AND failed.status_shipment = 'failed')",
            $gridTable,
            $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order')),
            (string)$this->dispatchStatus,
            $hopTable
        );

        $maxId = (int)$connection->fetchOne(sprintf(
            "SELECT MAX(order_id) FROM %s WHERE status_shipment = 'failed'",
            $hopTable
        ));

        for ($from = 0; $from < $maxId; $from += self::BATCH_SIZE) {
            $connection->query($sql, [$from, $from + self::BATCH_SIZE]);
        }

        return $this;
    }

    /**
     * Corre después del backfill general.
     *
     * En una instalación nueva, el backfill llena la columna en todos los pedidos; éste sólo
     * corrige los fallidos.
     *
     * @return array
     */
    public static function getDependencies()
    {
        return [BackfillHopDispatchStatus::class];
    }

    /**
     * Sin nombres anteriores.
     *
     * @return array
     */
    public function getAliases()
    {
        return [];
    }
}
