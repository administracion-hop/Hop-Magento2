<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Checkout;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Checkout\Block\Checkout\LayoutProcessor;
use Magento\Framework\App\ResourceConnection;

/**
 * Injects the Provincia/Distrito selects into the checkout shipping address form when the
 * "mapping" ubigeo source is configured to use the attributes created via the admin
 * "Crear campos de Distrito y Provincia" button.
 *
 * The attribute's own "used_in_forms" already makes core auto-inject a field for it, but with
 * a flat dataScope that Magento_Checkout/js/model/new-address never reads (see
 * Menze\Test\Plugin\Checkout\LayoutProcessorPlugin for the same issue) — so this plugin always
 * builds the field itself, scoped under custom_attributes, and wins by running after core.
 */
class UbigeoAddressAttributesLayoutProcessorPlugin
{
    public function __construct(
        private readonly Data $helper,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        if ($this->helper->getUbigeoSource() !== \Hop\Envios\Model\Config\Source\UbigeoSourceOption::MAPPING
            || $this->helper->getUbigeoProvinciaAttribute() !== PeruAddressAttributesManager::CODE_PROVINCIA
            || $this->helper->getUbigeoDistritoAttribute() !== PeruAddressAttributesManager::CODE_DISTRITO
        ) {
            return $jsLayout;
        }

        if (!isset(
            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
                ['children']['shippingAddress']['children']['shipping-address-fieldset']['children']
        )) {
            return $jsLayout;
        }

        $shippingFields = &$jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']
            ['children']['shippingAddress']['children']['shipping-address-fieldset']['children'];

        $provinciaCode = PeruAddressAttributesManager::CODE_PROVINCIA;
        $distritoCode  = PeruAddressAttributesManager::CODE_DISTRITO;

        // Read Region's actual sortOrder from the already-merged layout instead of assuming
        // core's default (90) — another customization outside this module could have moved it.
        $regionSortOrder = $this->getFieldSortOrder($shippingFields, 'region_id', 90);

        $shippingFields[$provinciaCode] = $this->buildSelectConfig(
            $provinciaCode,
            __('Provincia'),
            'shippingAddress',
            'shippingAddress.custom_attributes.' . $provinciaCode,
            $regionSortOrder + 1,
            $this->getProvinciaOptions()
        );
        $shippingFields[$provinciaCode]['filterBy'] = [
            'target' => '${ $.parentName }.region_id:value',
            'field'  => 'region_id',
        ];

        $shippingFields[$distritoCode] = $this->buildSelectConfig(
            $distritoCode,
            __('Distrito'),
            'shippingAddress',
            'shippingAddress.custom_attributes.' . $distritoCode,
            $regionSortOrder + 2,
            $this->getDistritoOptions()
        );
        $shippingFields[$distritoCode]['filterBy'] = [
            'target' => '${ $.parentName }.' . $provinciaCode . ':value',
            'field'  => 'provincia',
        ];

        return $jsLayout;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function getFieldSortOrder(array $fields, string $code, float $default): float
    {
        $sortOrder = $fields[$code]['sortOrder'] ?? null;

        return is_numeric($sortOrder) ? (float)$sortOrder : $default;
    }

    private function buildSelectConfig(
        string $id,
        \Magento\Framework\Phrase $label,
        string $customScope,
        string $dataScope,
        float $sortOrder,
        array $options
    ): array {
        return [
            'component' => 'Magento_Ui/js/form/element/select',
            'config'    => [
                'customScope' => $customScope,
                'template'    => 'ui/form/field',
                'elementTmpl' => 'ui/form/element/select',
                'id'          => $id,
            ],
            'dataScope'  => $dataScope,
            'label'      => $label,
            'provider'   => 'checkoutProvider',
            'sortOrder'  => $sortOrder,
            'visible'    => true,
            'options'    => array_merge([['value' => '', 'label' => __('-- Seleccionar --')]], $options),
            'validation' => ['required-entry' => false],
            'required'   => false,
        ];
    }

    /**
     * Each option carries its departamento's Magento region_id under the "region_id" key,
     * which the provincia field's declarative "filterBy" config matches against the native
     * region select's value — so Provincia only lists provincias of the chosen Region.
     *
     * @return array<int, array{value:string,label:string,region_id:string}>
     */
    private function getProvinciaOptions(): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('hop_peru_provincia');

        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['name', 'region_id'])->order('name ASC')
        );

        return array_map(
            static fn (array $row) => [
                'value'     => $row['name'],
                'label'     => $row['name'],
                'region_id' => (string)$row['region_id'],
            ],
            $rows
        );
    }

    /**
     * Each option carries the parent provincia name under the "provincia" key, which the
     * distrito field's declarative "filterBy" config matches against the provincia field's value.
     *
     * @return array<int, array{value:string,label:string,provincia:string}>
     */
    private function getDistritoOptions(): array
    {
        $connection    = $this->resourceConnection->getConnection();
        $distritoTable = $this->resourceConnection->getTableName('hop_peru_distrito');
        $provinciaTable = $this->resourceConnection->getTableName('hop_peru_provincia');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['d' => $distritoTable], [])
                ->joinInner(['p' => $provinciaTable], 'p.provincia_id = d.provincia_id', [])
                ->columns(['distrito_name' => 'd.name', 'provincia_name' => 'p.name'])
                ->distinct(true)
                ->order('d.name ASC')
        );

        return array_map(
            static fn (array $row) => [
                'value'     => $row['distrito_name'],
                'label'     => $row['distrito_name'],
                'provincia' => $row['provincia_name'],
            ],
            $rows
        );
    }
}
