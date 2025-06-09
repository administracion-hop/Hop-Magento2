<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Catalog\Model\Product;

/**
 * Patch to create HOP shipping attributes
 */
class HopAttributes implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    /**
     * Do Upgrade
     *
     * @return void
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributes = [
            'hop_alto' => 'Alto (cm)',
            'hop_largo' => 'Largo (cm)',
            'hop_ancho' => 'Ancho (cm)'
        ];

        foreach ($attributes as $attributeCode => $attributeLabel) {
            if (!$eavSetup->getAttributeId(Product::ENTITY, $attributeCode)) {
                $eavSetup->addAttribute(
                    Product::ENTITY,
                    $attributeCode,
                    [
                        'frontend'                => '',
                        'label'                   => $attributeLabel,
                        'input'                   => 'text',
                        'type'                    => 'int',
                        'class'                   => '',
                        'global'                  => ScopedAttributeInterface::SCOPE_GLOBAL,
                        'visible'                 => true,
                        'required'                => true,
                        'user_defined'            => false,
                        'default'                 => '',
                        'apply_to'                => '',
                        'fontend_class'           => 'validate-number',
                        'visible_on_front'        => false,
                        'is_used_in_grid'         => false,
                        'is_visible_in_grid'      => false,
                        'is_filterable_in_grid'   => false,
                        'used_in_product_listing' => true
                    ]
                );
            } else {
                $eavSetup->updateAttribute(
                    Product::ENTITY,
                    $attributeCode,
                    'is_required',
                    false
                );
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * Get array of patches that have to be executed prior to this.
     *
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * Get aliases (previous names) for the patch.
     *
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }
}