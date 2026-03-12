<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\OrderPickupPoint;
use Hop\Envios\Model\OrderPickupPointRepository;

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
     * @var OrderPickupPointRepository
     */
    private $orderPickupPointRepository;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param OrderPickupPointRepository $orderPickupPointRepository
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        OrderPickupPointRepository $orderPickupPointRepository
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
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
                ->from($tableName, ['entity_id', 'hop_data', 'shipping_description'])
                ->where('hop_data IS NOT NULL')
                ->where('hop_data != ""');

            $orders = $connection->fetchAll($select);

            foreach ($orders as $order) {
                try {
                    $hopEnviosData = json_decode($order['hop_data'], true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($hopEnviosData)) {
                        $hopPointId = isset($hopEnviosData['hopPointId']) ? $hopEnviosData['hopPointId'] : null;

                        if ($hopPointId !== null) {
                            /** @var OrderPickupPoint */
                            $orderPickupPoint = $this->orderPickupPointRepository->create();
                            $orderPickupPoint->setOrderId((int)$order['entity_id']);
                            $orderPickupPoint->setOriginalShippingDescription($order['shipping_description']);
                            $orderPickupPoint->setOriginalPickupPointId($hopPointId);
                            $orderPickupPoint->setPickupPointId($hopPointId);
                            $this->orderPickupPointRepository->save($orderPickupPoint);
                        } else {
                            $this->logger->warning(sprintf(
                                'Hop_Envios - Order ID: %s - No se encontró hopPointId en el JSON',
                                $order['entity_id']
                            ));
                        }
                    } else {
                        $this->logger->error(sprintf(
                            'Hop_Envios - Order ID: %s - JSON inválido: %s',
                            $order['entity_id'],
                            $order['hop_data']
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
