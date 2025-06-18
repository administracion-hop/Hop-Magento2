<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class MigrateHopData implements DataPatchInterface
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

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName('sales_order');

            $select = $connection->select()
                ->from($tableName, ['entity_id', 'quote_id', 'hop_envios'])
                ->where('hop_envios IS NOT NULL')
                ->where('hop_envios != ""');

            $orders = $connection->fetchAll($select);

            foreach ($orders as $order) {
                try {
                    $hopEnviosData = json_decode($order['hop_envios'], true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($hopEnviosData)) {
                        $hopPointId = isset($data['hopPointId']) ? $hopEnviosData['hopPointId'] : null;

                        if ($hopPointId !== null) {
                            $this->logger->info(sprintf(
                                'Hop_Envios - Order ID: %s, Quote ID: %s, HopPointId: %s',
                                $order['entity_id'],
                                $order['quote_id'],
                                $hopPointId
                            ));
                        } else {
                            $this->logger->warning(sprintf(
                                'Hop_Envios - Order ID: %s, Quote ID: %s - No se encontró hopPointId en el JSON',
                                $order['entity_id'],
                                $order['quote_id']
                            ));
                        }
                    } else {
                        $this->logger->error(sprintf(
                            'Hop_Envios - Order ID: %s, Quote ID: %s - JSON inválido: %s',
                            $order['entity_id'],
                            $order['quote_id'],
                            $order['hop_envios']
                        ));
                    }
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Hop_Envios - Error procesando Order ID: %s - %s',
                        $order['entity_id'],
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Hop_Envios Patch Error: ' . $e->getMessage());
            throw $e;
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}