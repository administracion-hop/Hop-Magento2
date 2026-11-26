<?php

declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\OrderGrid\DispatchStatusExpression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * Registra como multibulto parcial los despachos que quedaron pendientes con bultos rechazados.
 *
 * Hasta la tarea 18065, cuando Hop rechazaba uno o más bultos de un multibulto, el módulo
 * guardaba cada bulto en hop_envios_shipment (los aceptados con su tracking, los rechazados en
 * failed) pero no registraba el fallo: el despacho quedaba en pending. La grilla los muestra
 * "No despachado", y "Enviar a HOP" o "Despachar a Hop" no mandan nada porque los envíos ya
 * están procesados. Desde la 18065 ese fallo se registra como failed con el código
 * multibulto_partial; este parche deja igual los que ya estaban trabados y recalcula su fila de
 * la grilla con el mismo CASE de la columna, para que pasen a "Parcial".
 *
 * Solo toca despachos pending o processing con al menos un bulto failed: los despachados o ya
 * fallidos no cambian. Es idempotente; un corte a mitad se resuelve volviendo a correr
 * setup:upgrade.
 *
 * @version 1.0.0
 * @copyright Copyright (c) 2025 Hop Envíos
 */
class RepairStuckMultibultoDispatch implements DataPatchInterface, NonTransactionableInterface
{
    /**
     * Pedidos por UPDATE de la grilla. Se esperan pocos: el límite es para no armar un IN enorme.
     */
    private const BATCH_SIZE = 1000;

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
        $hopTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('hop_envios'));
        $bultoTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('hop_envios_shipment'));

        $stuck = sprintf(
            "%s.status_shipment IN ('pending', 'processing')"
            . " AND EXISTS (SELECT 1 FROM %s AS bulto WHERE bulto.hop_envio_id = %s.entity_id"
            . " AND bulto.status = 'failed')",
            $hopTable,
            $bultoTable,
            $hopTable
        );

        // Se esperan pocos: se actualizan de a uno, con el mismo motivo que deja el despacho
        // (DispatchError::multibultoPartialMessage), que cuenta solo los bultos mandados a Hop.
        $rows = $connection->fetchAll(sprintf(
            'SELECT %1$s.entity_id, %1$s.order_id,'
            . " (SELECT COUNT(*) FROM %2\$s AS b WHERE b.hop_envio_id = %1\$s.entity_id AND b.status = 'failed')"
            . ' AS rejected,'
            . " (SELECT COUNT(*) FROM %2\$s AS b WHERE b.hop_envio_id = %1\$s.entity_id"
            . " AND b.status IN ('completed', 'failed')) AS sent"
            . ' FROM %1$s WHERE %3$s',
            $hopTable,
            $bultoTable,
            $stuck
        ));
        if (!$rows) {
            return $this;
        }

        $orderIds = [];
        foreach ($rows as $row) {
            $connection->query(
                sprintf(
                    'UPDATE %s SET status_shipment = ?, last_error_code = ?, last_error = ?, next_retry_at = NULL'
                    . ' WHERE entity_id = ? AND %s',
                    $hopTable,
                    $stuck
                ),
                [
                    'failed',
                    DispatchError::CODE_MULTIBULTO_PARTIAL,
                    DispatchError::multibultoPartialMessage($row['rejected'], $row['sent']),
                    (int)$row['entity_id'],
                ]
            );
            $orderIds[(int)$row['order_id']] = (int)$row['order_id'];
        }
        $orderIds = array_values($orderIds);

        $gridSql = sprintf(
            'UPDATE %s AS grid'
            . ' INNER JOIN %s AS sales_order ON sales_order.entity_id = grid.entity_id'
            . ' SET grid.hop_dispatch_status = %s'
            . ' WHERE grid.entity_id IN (%%s)',
            $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order_grid')),
            $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order')),
            (string)$this->dispatchStatus
        );
        foreach (array_chunk($orderIds, self::BATCH_SIZE) as $batch) {
            $connection->query(sprintf($gridSql, implode(',', $batch)));
        }

        return $this;
    }

    /**
     * Después del backfill general de la columna y del recálculo de los fallidos.
     *
     * @return array
     */
    public static function getDependencies()
    {
        return [BackfillHopDispatchStatus::class, RefreshHopDispatchStatusFailures::class];
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
