<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;

/**
 * Hasta la tarea 18065, Helper\ShippingMethod::createShipmentData() escribía el error de la API
 * de Hop en sales_order.shipping_description, y el comprador lo veía en vez de su punto de
 * retiro. La mayoría de esos pedidos se despacharon igual después (el cron los tomaba como
 * pending), así que quedaron despachados con el error a la vista. El error ahora vive en
 * hop_envios.last_error; acá se devuelve la descripción del punto guardada en el checkout.
 */
class RestorePickupShippingDescription implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $connection = $this->resourceConnection->getConnection();
            $order = $this->resourceConnection->getTableName('sales_order');
            $grid = $this->resourceConnection->getTableName('sales_order_grid');
            $pickup = $this->resourceConnection->getTableName('hop_envios_order_pickup_point');
            $legacyError = "(o.shipping_description LIKE 'Hubo un error al enviar su pedido a Hop%'"
                . " OR o.shipping_description LIKE 'No se pudo generar el envío en Hop%')";

            // La grilla primero: después de actualizar sales_order ya no se puede filtrar por el error.
            $connection->query(
                "UPDATE {$grid} g JOIN {$order} o ON o.entity_id = g.entity_id"
                . " JOIN {$pickup} p ON p.order_id = o.entity_id"
                . " SET g.shipping_information = p.original_shipping_description"
                . " WHERE {$legacyError} AND p.original_shipping_description <> ''"
            );
            $updated = $connection->query(
                "UPDATE {$order} o JOIN {$pickup} p ON p.order_id = o.entity_id"
                . " SET o.shipping_description = p.original_shipping_description"
                . " WHERE {$legacyError} AND p.original_shipping_description <> ''"
            )->rowCount();

            $this->logger->info(sprintf(
                'RestorePickupShippingDescription: %d pedidos recuperaron la descripción de su punto de retiro.',
                $updated
            ));
        } catch (\Exception $e) {
            $this->logger->error('RestorePickupShippingDescription error: ' . $e->getMessage());
            throw $e;
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}
