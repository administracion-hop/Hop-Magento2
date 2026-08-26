<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo;

use Hop\Envios\Model\Config\Source\UbigeoSourceOption;
use Hop\Envios\Model\Ubigeo\Source\PeruDistritoSource;
use Hop\Envios\Model\Ubigeo\Source\PeruProvinciaSource;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Cache\Type as EavCacheType;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;

/**
 * Creates/removes the "Provincia (Perú)" and "Distrito (Perú)" customer_address select
 * attributes, and wires/unwires them into the "shipping/hop_peru/ubigeo_*_attribute" config,
 * on demand from the admin config screen — never run automatically.
 */
class PeruAddressAttributesManager
{
    public const CODE_PROVINCIA = 'hop_ubigeo_provincia';
    public const CODE_DISTRITO  = 'hop_ubigeo_distrito';

    private const CONFIG_PATH_SOURCE     = 'shipping/hop_peru/ubigeo_source';
    private const CONFIG_PATH_PROVINCIA = 'shipping/hop_peru/ubigeo_provincia_attribute';
    private const CONFIG_PATH_DISTRITO  = 'shipping/hop_peru/ubigeo_distrito_attribute';

    public function __construct(
        private readonly CustomerSetupFactory $customerSetupFactory,
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig,
        private readonly TypeListInterface $cacheTypeList
    ) {
    }

    /**
     * DB-direct existence check (not the cached Magento\Eav\Model\Config) — the "cache user
     * defined attributes" setting caches attribute lookups (including negative ones) across
     * requests, so relying on it here could report "not created" right after a successful
     * create() in another request and send addAttribute() straight into a duplicate insert.
     */
    public function isCreated(): bool
    {
        $customerSetup = $this->createCustomerSetup();

        foreach ([self::CODE_PROVINCIA, self::CODE_DISTRITO] as $code) {
            if (!$customerSetup->getAttributeId('customer_address', $code)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $scope Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_* value
     *     matching the config screen the admin was on when clicking the button, so the value is
     *     written at that same scope instead of always overriding "Use Default".
     */
    public function create(string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, int $scopeId = 0): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $customerSetup = $this->createCustomerSetup();

            $attributes = [
                self::CODE_PROVINCIA => ['label' => 'Provincia (Perú)', 'source' => PeruProvinciaSource::class],
                self::CODE_DISTRITO  => ['label' => 'Distrito (Perú)', 'source' => PeruDistritoSource::class],
            ];

            $sortOrder = 130;
            foreach ($attributes as $code => $info) {
                // Guard per attribute (DB-direct), not the pair as a whole, so a retry after a
                // partial failure doesn't try to re-insert the one that already succeeded.
                if (!$customerSetup->getAttributeId('customer_address', $code)) {
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
                }

                $sortOrder += 10;
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
            $this->cacheTypeList->cleanType(EavCacheType::TYPE_IDENTIFIER);
        }

        $this->configWriter->save(self::CONFIG_PATH_SOURCE, UbigeoSourceOption::MAPPING, $scope, $scopeId);
        $this->configWriter->save(self::CONFIG_PATH_PROVINCIA, self::CODE_PROVINCIA, $scope, $scopeId);
        $this->configWriter->save(self::CONFIG_PATH_DISTRITO, self::CODE_DISTRITO, $scope, $scopeId);
        $this->reinitableConfig->reinit();
    }

    public function revert(string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, int $scopeId = 0): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        try {
            $customerSetup = $this->createCustomerSetup();

            foreach ([self::CODE_PROVINCIA, self::CODE_DISTRITO] as $code) {
                $customerSetup->removeAttribute('customer_address', $code);
            }
        } finally {
            $this->moduleDataSetup->getConnection()->endSetup();
            $this->cacheTypeList->cleanType(EavCacheType::TYPE_IDENTIFIER);
        }

        // Only clear the config if it still points at the attributes we created —
        // an admin may have since repointed it at a different attribute manually.
        if ($this->scopeConfig->getValue(self::CONFIG_PATH_PROVINCIA, $scope, $scopeId) === self::CODE_PROVINCIA) {
            $this->configWriter->delete(self::CONFIG_PATH_PROVINCIA, $scope, $scopeId);
        }
        if ($this->scopeConfig->getValue(self::CONFIG_PATH_DISTRITO, $scope, $scopeId) === self::CODE_DISTRITO) {
            $this->configWriter->delete(self::CONFIG_PATH_DISTRITO, $scope, $scopeId);
        }
        $this->reinitableConfig->reinit();
    }

    private function createCustomerSetup(): CustomerSetup
    {
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->moduleDataSetup]);

        return $customerSetup;
    }
}
