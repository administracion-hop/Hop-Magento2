<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo;

use Hop\Envios\Model\Ubigeo\Source\PeruDistritoSource;
use Hop\Envios\Model\Ubigeo\Source\PeruProvinciaSource;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Creates/removes the "Provincia (Perú)" and "Distrito (Perú)" customer_address select
 * attributes, and wires/unwires them into the "shipping/hop/ubigeo_*_attribute" config,
 * on demand from the admin config screen — never run automatically.
 */
class PeruAddressAttributesManager
{
    public const CODE_PROVINCIA = 'hop_ubigeo_provincia';
    public const CODE_DISTRITO  = 'hop_ubigeo_distrito';

    private const CONFIG_PATH_PROVINCIA = 'shipping/hop/ubigeo_provincia_attribute';
    private const CONFIG_PATH_DISTRITO  = 'shipping/hop/ubigeo_distrito_attribute';

    public function __construct(
        private readonly CustomerSetupFactory $customerSetupFactory,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavConfig $eavConfig,
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    public function isCreated(): bool
    {
        foreach ([self::CODE_PROVINCIA, self::CODE_DISTRITO] as $code) {
            $attribute = $this->eavConfig->getAttribute('customer_address', $code);
            if (!$attribute || !$attribute->getId()) {
                return false;
            }
        }

        return true;
    }

    public function create(): void
    {
        if ($this->isCreated()) {
            return;
        }

        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            /** @var CustomerSetup $customerSetup */
            $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

            $attributes = [
                self::CODE_PROVINCIA => ['label' => 'Provincia (Perú)', 'source' => PeruProvinciaSource::class],
                self::CODE_DISTRITO  => ['label' => 'Distrito (Perú)', 'source' => PeruDistritoSource::class],
            ];

            $sortOrder = 130;
            foreach ($attributes as $code => $info) {
                $customerSetup->addAttribute('customer_address', $code, [
                    'type'                  => 'varchar',
                    'label'                 => $info['label'],
                    'input'                 => 'select',
                    'source'                => $info['source'],
                    'required'              => false,
                    'visible'               => true,
                    'position'              => $sortOrder,
                    'sort_order'            => $sortOrder,
                    'system'                => false,
                    'is_used_in_grid'       => false,
                    'is_visible_in_grid'    => false,
                    'is_filterable_in_grid' => false,
                    'is_searchable_in_grid' => false,
                ]);

                $attribute = $customerSetup->getEavConfig()->getAttribute('customer_address', $code);
                $attribute->setData(
                    'used_in_forms',
                    ['adminhtml_customer_address', 'customer_address_edit', 'customer_register_address']
                );
                $attribute->save();

                $sortOrder += 10;
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }

        $this->configWriter->save(self::CONFIG_PATH_PROVINCIA, self::CODE_PROVINCIA);
        $this->configWriter->save(self::CONFIG_PATH_DISTRITO, self::CODE_DISTRITO);
        $this->reinitableConfig->reinit();
    }

    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            /** @var CustomerSetup $customerSetup */
            $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

            foreach ([self::CODE_PROVINCIA, self::CODE_DISTRITO] as $code) {
                $customerSetup->removeAttribute('customer_address', $code);
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
        }

        // Only clear the config if it still points at the attributes we created —
        // an admin may have since repointed it at a different attribute manually.
        if ($this->scopeConfig->getValue(self::CONFIG_PATH_PROVINCIA) === self::CODE_PROVINCIA) {
            $this->configWriter->delete(self::CONFIG_PATH_PROVINCIA);
        }
        if ($this->scopeConfig->getValue(self::CONFIG_PATH_DISTRITO) === self::CODE_DISTRITO) {
            $this->configWriter->delete(self::CONFIG_PATH_DISTRITO);
        }
        $this->reinitableConfig->reinit();
    }
}
