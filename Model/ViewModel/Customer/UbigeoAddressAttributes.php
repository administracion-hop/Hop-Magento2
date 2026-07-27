<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ViewModel\Customer;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\Config\Source\UbigeoSourceOption;
use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Hop\Envios\Model\Ubigeo\PeruLocationOptionsProvider;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Backs the Provincia/Distrito selects injected into the customer account "Address Book"
 * form (view/frontend/templates/address/edit.phtml override).
 *
 * Magento_Customer's frontend address-edit template is a hand-written phtml with no generic
 * "render remaining EAV attributes" loop (unlike the admin customer form), so the
 * "Provincia (Perú)" / "Distrito (Perú)" attributes never show up there on their own even
 * though their `used_in_forms` includes `customer_address_edit` — that setting only affects
 * \Magento\Customer\Model\Metadata\Form's data *extraction* on save, not this template's markup.
 * Same gating condition as Hop\Envios\Plugin\Checkout\UbigeoAddressAttributesLayoutProcessorPlugin:
 * only show these fields when "mapping" is selected AND it points at the attributes this
 * module itself created, so a store using its own custom attributes isn't shown a form it
 * didn't ask for.
 */
class UbigeoAddressAttributes implements ArgumentInterface
{
    public function __construct(
        private readonly Data $helper,
        private readonly PeruLocationOptionsProvider $optionsProvider
    ) {
    }

    public function isVisible(): bool
    {
        return $this->helper->getUbigeoSource() === UbigeoSourceOption::MAPPING
            && $this->helper->getUbigeoProvinciaAttribute() === PeruAddressAttributesManager::CODE_PROVINCIA
            && $this->helper->getUbigeoDistritoAttribute() === PeruAddressAttributesManager::CODE_DISTRITO;
    }

    public function getProvinciaAttributeCode(): string
    {
        return PeruAddressAttributesManager::CODE_PROVINCIA;
    }

    public function getDistritoAttributeCode(): string
    {
        return PeruAddressAttributesManager::CODE_DISTRITO;
    }

    /**
     * @return array<int, array{value:string,label:string,region_id:string}>
     */
    public function getProvinciaOptions(): array
    {
        return $this->optionsProvider->getProvinciaOptions();
    }

    /**
     * @return array<int, array{value:string,label:string,provincia:string}>
     */
    public function getDistritoOptions(): array
    {
        return $this->optionsProvider->getDistritoOptions();
    }

    public function getSelectedProvincia(?AddressInterface $address): string
    {
        return $this->getAttributeValue($address, PeruAddressAttributesManager::CODE_PROVINCIA);
    }

    public function getSelectedDistrito(?AddressInterface $address): string
    {
        return $this->getAttributeValue($address, PeruAddressAttributesManager::CODE_DISTRITO);
    }

    private function getAttributeValue(?AddressInterface $address, string $code): string
    {
        if ($address === null) {
            return '';
        }

        $attribute = $address->getCustomAttribute($code);

        return $attribute !== null ? (string)$attribute->getValue() : '';
    }
}
