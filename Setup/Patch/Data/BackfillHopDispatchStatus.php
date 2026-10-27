<?php

declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Hop\Envios\Model\OrderGrid\DispatchStatusExpression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Llena hop_dispatch_status en las filas de sales_order_grid que ya existían.
 *
 * El agregador de la grilla sólo reescribe la fila cuando el pedido se guarda, así que sin
 * esto los pedidos viejos quedarían con la columna vacía (y fuera de cualquier filtro).
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Setup\Patch\Data
 */
class BackfillHopDispatchStatus implements DataPatchInterface
{
    /**
     * Pedidos por tanda. Un UPDATE con join sobre sales_order_grid entera deja la tabla
     * bloqueada todo lo que dure; en tandas el lock es corto aunque el total tarde lo mismo.
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
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $gridTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order_grid'));

        $sql = sprintf(
            'UPDATE %s AS grid'
            . ' INNER JOIN %s AS sales_order ON sales_order.entity_id = grid.entity_id'
            . ' SET grid.hop_dispatch_status = %s'
            . ' WHERE grid.entity_id > ? AND grid.entity_id <= ?',
            $gridTable,
            $connection->quoteIdentifier($this->moduleDataSetup->getTable('sales_order')),
            (string)$this->dispatchStatus
        );

        $maxId = (int)$connection->fetchOne('SELECT MAX(entity_id) FROM ' . $gridTable);

        for ($from = 0; $from < $maxId; $from += self::BATCH_SIZE) {
            $connection->query($sql, [$from, $from + self::BATCH_SIZE]);
        }

        return $this;
    }

    /**
     * BackfillStatusShipmentCompleted corrige status_shipment con un UPDATE directo, sin refrescar
     * la grilla: si corriera después (los patches se aplican en orden alfabético), los pedidos
     * que marca como completed quedarían "No despachado".
     *
     * @return array
     */
    public static function getDependencies()
    {
        return [BackfillStatusShipmentCompleted::class];
    }

    /**
     * @return array
     */
    public function getAliases()
    {
        return [];
    }
}
