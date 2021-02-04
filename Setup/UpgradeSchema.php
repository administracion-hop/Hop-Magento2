<?php

namespace Improntus\Hop\Setup;

use Magento\Framework\Setup\UpgradeSchemaInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * Class UpgradeSchema
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Setup
 */
class UpgradeSchema implements UpgradeSchemaInterface
{
    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @throws \Zend_Db_Exception
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        if(version_compare($context->getVersion(), '1.0.1', '<')) {

            $shipmentHop = [
                'type'    => Table::TYPE_TEXT,
                'nullable'=> true,
                'comment' => 'Send shipping hop',
                'default' => null
            ];

            if (!$installer->getConnection()->tableColumnExists($installer->getTable('sales_order'), 'hop'))
            {
                $installer->getConnection()->addColumn($installer->getTable('sales_order'), 'hop', $shipmentHop);
            }

        }

        if(version_compare($context->getVersion(), '1.0.2', '<')) {

            $improntusHop = $installer->getConnection()
                ->newTable($installer->getTable('improntus_hop'))
                ->addColumn(
                    'entity_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'nullable' => false, 'primary' => true],
                    'Id autoincremental'
                )
                ->addColumn(
                    'order_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false],
                    'Order id'
                )
                ->addColumn('increment_id', Table::TYPE_TEXT, null, ['nullable' => false])
                ->addColumn('info_hop', Table::TYPE_TEXT, null, ['nullable' => true])
                ->setComment('Guarda info del shipping generado para envios hop');

            $installer->getConnection()->createTable($improntusHop);

        }

        if(version_compare($context->getVersion(), '1.0.3', '<')) {

            $hopData = [
                'type'    => Table::TYPE_TEXT,
                'nullable'=> true,
                'comment' => 'Hop data',
                'default' => null
            ];

            if (!$installer->getConnection()->tableColumnExists($installer->getTable('quote'), 'hop_data'))
            {
                $installer->getConnection()->addColumn($installer->getTable('quote'), 'hop_data', $hopData);
            }

            if (!$installer->getConnection()->tableColumnExists($installer->getTable('sales_order'), 'hop_data'))
            {
                $installer->getConnection()->addColumn($installer->getTable('sales_order'), 'hop_data', $hopData);
            }
        }

        $installer->endSetup();
    }
}
