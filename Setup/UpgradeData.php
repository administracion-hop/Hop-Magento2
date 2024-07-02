<?php

namespace Improntus\Hop\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Eav\Setup\EavSetupFactory;

/**
 * Class UpgradeData
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Setup
 */
class UpgradeData implements UpgradeDataInterface
{
    /**
     * @var EavSetupFactory
     */
    private $_eavSetupFactory;

    /**
     * InstallData constructor.
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(EavSetupFactory $eavSetupFactory)
    {
        $this->_eavSetupFactory = $eavSetupFactory;
    }

    /**
     * Upgrades data for a module
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $eavSetup = $this->_eavSetupFactory->create(['setup' => $setup]);

        if (version_compare($context->getVersion(), '1.0.1', '<'))
        {
            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'hop_alto',
                [
                    'frontend'  => '',
                    'label'     => 'Alto (cm)',
                    'input'     => 'text',
                    'type'      => 'int',
                    'class'     => '',
                    'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'   => true,
                    'required'  => true,
                    'user_defined' => false,
                    'default'   => '',
                    'apply_to'  => '',
                    'fontend_class'           => 'validate-number',
                    'visible_on_front'        => false,
                    'is_used_in_grid'         => false,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => false,
                    'used_in_product_listing' => true
                ]
            );

            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'hop_largo',
                [
                    'frontend'  => '',
                    'label'     => 'Largo (cm)',
                    'input'     => 'text',
                    'type'      => 'int',
                    'class'     => '',
                    'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'   => true,
                    'required'  => true,
                    'user_defined' => false,
                    'default'   => '',
                    'apply_to'  => '',
                    'fontend_class'           => 'validate-number',
                    'visible_on_front'        => false,
                    'is_used_in_grid'         => false,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => false,
                    'used_in_product_listing' => true
                ]
            );

            $eavSetup->addAttribute(
                \Magento\Catalog\Model\Product::ENTITY,
                'hop_ancho',
                [
                    'frontend'  => '',
                    'label'     => 'Ancho (cm)',
                    'input'     => 'text',
                    'type'      => 'int',
                    'class'     => '',
                    'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                    'visible'   => true,
                    'required'  => true,
                    'user_defined' => false,
                    'default'   => '',
                    'apply_to'  => '',
                    'fontend_class'           => 'validate-number',
                    'visible_on_front'        => false,
                    'is_used_in_grid'         => false,
                    'is_visible_in_grid'      => false,
                    'is_filterable_in_grid'   => false,
                    'used_in_product_listing' => true
                ]
            );
        }

        $setup->endSetup();
    }
}
