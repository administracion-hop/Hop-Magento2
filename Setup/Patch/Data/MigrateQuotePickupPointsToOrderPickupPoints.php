<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\OrderPickupPointRepository;

class MigrateQuotePickupPointsToOrderPickupPoints implements DataPatchInterface
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

            $quoteTable = $this->resourceConnection->getTableName('hop_envios_selected_pickup_point');
            $orderTable = $this->resourceConnection->getTableName('sales_order');

            // JOIN hop_envios_selected_pickup_point con sales_order para obtener el order_id a partir del quote_id
            $select = $connection->select()
                ->from(['qpp' => $quoteTable], [
                    'original_pickup_point_id',
                    'pickup_point_id',
                    'original_shipping_description',
                    'original_zip_code',
                ])
                ->join(
                    ['so' => $orderTable],
                    'qpp.quote_id = so.quote_id',
                    ['order_id' => 'so.entity_id']
                );

            $rows = $connection->fetchAll($select);

            foreach ($rows as $row) {
                try {
                    $orderPickupPoint = $this->orderPickupPointRepository->create();
                    $orderPickupPoint->setOrderId((int)$row['order_id']);
                    $orderPickupPoint->setOriginalPickupPointId($row['original_pickup_point_id']);
                    $orderPickupPoint->setPickupPointId($row['pickup_point_id']);
                    $orderPickupPoint->setOriginalShippingDescription($row['original_shipping_description']);
                    $orderPickupPoint->setOriginalZipCode($row['original_zip_code']);
                    $this->orderPickupPointRepository->save($orderPickupPoint);
                } catch (\Exception $e) {
                    $this->logger->error(sprintf(
                        'Hop_Envios - Error migrando pickup point para Order ID: %s - %s',
                        $row['order_id'],
                        $e->getMessage()
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Hop_Envios MigrateQuotePickupPointsToOrderPickupPoints Error: ' . $e->getMessage());
            throw $e;
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [
            MigrateHopData::class,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
