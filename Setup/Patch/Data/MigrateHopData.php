<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\App\ResourceConnection;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\SelectedPickupPoint;
use Hop\Envios\Model\SelectedPickupPointRepository;

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
     * @var SelectedPickupPointRepository
     */
    private $selectedPickupPointRepository;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     * @param SelectedPickupPointRepository $selectedPickupPointRepository
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection,
        LoggerInterface $logger,
        SelectedPickupPointRepository $selectedPickupPointRepository
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
        $this->selectedPickupPointRepository = $selectedPickupPointRepository;
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
                ->from($tableName, ['entity_id', 'quote_id', 'hop_data', 'shipping_description'])
                ->where('hop_data IS NOT NULL')
                ->where('hop_data != ""');

            $orders = $connection->fetchAll($select);

            foreach ($orders as $order) {
                try {
                    $hopEnviosData = json_decode($order['hop_data'], true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($hopEnviosData)) {
                        $hopPointId = isset($hopEnviosData['hopPointId']) ? $hopEnviosData['hopPointId'] : null;

                        if ($hopPointId !== null) {
                            /** @var SelectedPickupPoint  */
                            $selectedPickupPoint = $this->selectedPickupPointRepository->create();
                            $selectedPickupPoint->setOrderId($order['entity_id']);
                            $selectedPickupPoint->setQuoteId($order['quote_id']);
                            $selectedPickupPoint->setOriginalShippingDescription($order['shipping_description']);
                            $selectedPickupPoint->setOriginalPickupPointId($hopPointId);
                            $selectedPickupPoint->setPickupPointId($hopPointId);
                            $this->selectedPickupPointRepository->save($selectedPickupPoint);
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