<?php

declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Hop\Envios\Model\DispatchError;
use Hop\Envios\Model\OrderGrid\DispatchStatusExpression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;

/**
 * Reabre los despachos marcados como despachados sin ningún registro de Hop.
 *
 * Hasta la tarea 18065, cuando Hop rechazaba entera la solicitud de un multibulto (por ejemplo,
 * un error de validación como {"package.1.size_category": [...]}), la respuesta no traía un
 * resultado por bulto y el módulo la daba por buena: marcaba el despacho como completed sin
 * guardar ningún bulto ni la respuesta de Hop. La grilla los muestra "Despachado" aunque Hop no
 * tiene el envío, y como figuran despachados nadie los reintenta.
 *
 * Se reconocen porque no dejaron ningún rastro de Hop: completed, sin info_hop, sin filas en
 * hop_envios_shipment y con al menos un envío de Magento. Todo despacho real deja uno de los dos
 * registros: el camino de un envío guarda info_hop y su bulto, el multibulto guarda sus bultos y
 * el despacho directo ("Enviar a HOP" sin envío de Magento) guarda info_hop.
 *
 * Quedan en failed con el código CODE_UNCONFIRMED_DISPATCH ("Error al despachar") y sin
 * reintento automático. El parche no consulta a Hop: al reintentarlos, el despacho primero busca
 * en Hop si el envío ya existe (SalesOrderShipmentSaveAfter::recoverUnconfirmedDispatch), porque
 * Hop acepta un segundo multibulto con la misma referencia.
 *
 * Es idempotente; un corte a mitad se resuelve volviendo a correr setup:upgrade.
 *
 * @version 1.0.0
 * @copyright Copyright (c) 2025 Hop Envíos
 */
class ReopenDispatchWithoutHopRecord implements DataPatchInterface, NonTransactionableInterface
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
     * Constructor.
     *
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
        $shipmentTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_shipment'));

        $withoutHopRecord = sprintf(
            "%1\$s.status_shipment = 'completed'"
            . " AND (%1\$s.info_hop IS NULL OR %1\$s.info_hop = '')"
            . ' AND NOT EXISTS (SELECT 1 FROM %2$s AS bulto WHERE bulto.hop_envio_id = %1$s.entity_id)'
            . ' AND EXISTS (SELECT 1 FROM %3$s AS shipment WHERE shipment.order_id = %1$s.order_id)',
            $hopTable,
            $bultoTable,
            $shipmentTable
        );

        $orderIds = array_map('intval', $connection->fetchCol(
            sprintf('SELECT DISTINCT order_id FROM %s WHERE %s', $hopTable, $withoutHopRecord)
        ));
        if (!$orderIds) {
            return $this;
        }

        $connection->query(
            sprintf(
                'UPDATE %s SET status_shipment = ?, last_error_code = ?, last_error = ?, next_retry_at = NULL'
                . ' WHERE %s',
                $hopTable,
                $withoutHopRecord
            ),
            ['failed', DispatchError::CODE_UNCONFIRMED_DISPATCH, DispatchError::unconfirmedDispatchMessage()]
        );

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
     * Corre después del backfill general de la columna y de los demás recálculos de esta versión.
     *
     * @return array
     */
    public static function getDependencies()
    {
        return [
            BackfillHopDispatchStatus::class,
            RefreshHopDispatchStatusFailures::class,
            RepairStuckMultibultoDispatch::class,
        ];
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
