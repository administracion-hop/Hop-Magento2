<?php

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;
use Hop\Envios\Model\EcomStatuses;

class CreateStatuses implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var StatusFactory
     */
    protected $statusFactory;

    /**
     * @var StatusResourceFactory
     */
    protected $statusResourceFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param StatusFactory $statusFactory
     * @param StatusResourceFactory $statusResourceFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        StatusFactory $statusFactory,
        StatusResourceFactory $statusResourceFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->statusFactory = $statusFactory;
        $this->statusResourceFactory = $statusResourceFactory;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        foreach (EcomStatuses::STATUSES as $code => $label) {
            $this->createStatus($code, $label);
        }
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * Create status and assign to state
     *
     * @param string $code
     * @param string $label
     * @return void
     */
    protected function createStatus($code, $label)
    {
        $states = [
            Order::STATE_PROCESSING,
            Order::STATE_COMPLETE
        ];
        foreach ($states as $state) {
            $connection = $this->statusResourceFactory->create()->getConnection();
            $select = $connection->select()->from(
                $this->statusResourceFactory->create()->getMainTable(),
                'status'
            )->where(
                'status = ?',
                $code
            );

            $existingStatus = $connection->fetchOne($select);

            if (!$existingStatus) {
                /** @var Status $status */
                $status = $this->statusFactory->create();

                $status->setData([
                    'status' => $state . '_' . $code,
                    'label' => $label,
                ]);

                /** @var StatusResource $statusResource */
                $statusResource = $this->statusResourceFactory->create();
                $statusResource->save($status);

                $status->assignState($state, false, true);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }


    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
