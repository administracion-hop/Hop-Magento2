<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;

/**
 * Orders dispatched to Hop before hop_envios.status_shipment was written on every dispatch
 * path (see SalesOrderShipmentSaveAfter, Helper\ShippingMethod::createShipmentData) are stuck
 * at the column's default 'pending' even though info_hop holds a real tracking/label response.
 * Plugin\Widget\Context now gates the "Descargar etiqueta HOP" / "Estado HOP" admin buttons on
 * status_shipment === 'completed', so without this backfill those historical orders lose the
 * buttons despite already having a valid Hop shipment.
 */
class BackfillStatusShipmentCompleted implements DataPatchInterface
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
            $tableName = $this->resourceConnection->getTableName('hop_envios');

            $updated = $connection->update(
                $tableName,
                ['status_shipment' => 'completed'],
                [
                    'status_shipment != ?' => 'completed',
                    'info_hop IS NOT NULL',
                    "info_hop != ''",
                ]
            );

            $this->logger->info(sprintf(
                'BackfillStatusShipmentCompleted: %d registros de hop_envios marcados como completed.',
                $updated
            ));
        } catch (\Exception $e) {
            $this->logger->error('BackfillStatusShipmentCompleted error: ' . $e->getMessage());
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
