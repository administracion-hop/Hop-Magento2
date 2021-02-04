<?php

namespace Improntus\Hop\Setup;

use Magento\Framework\Setup\InstallSchemaInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\DB\Ddl\Table;

/**
 * Class InstallSchema
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Setup
 */
class InstallSchema implements InstallSchemaInterface
{
    /**
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

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

        $installer->endSetup();
    }
}