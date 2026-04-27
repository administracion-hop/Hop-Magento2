<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;

class CleanPickupPointsWithoutClientId implements DataPatchInterface
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
            $tableName = $this->resourceConnection->getTableName('hop_pickup_points');

            $deleted = $connection->delete(
                $tableName,
                ['client_id IS NULL OR client_id = ""']
            );

            $this->logger->info(sprintf(
                'CleanPickupPointsWithoutClientId: eliminados %d registros sin client_id de hop_pickup_points.',
                $deleted
            ));
        } catch (\Exception $e) {
            $this->logger->error('CleanPickupPointsWithoutClientId error: ' . $e->getMessage());
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
