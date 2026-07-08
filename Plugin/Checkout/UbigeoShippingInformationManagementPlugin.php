<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Checkout;

use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\Data\AddressInterface;

/**
 * Bridges the Peru ubigeo address attributes from extension_attributes onto the quote address
 * flat data before it is saved, so they persist on the quote_address table columns. Covers the
 * "shipping-information" save (e.g. selecting a saved customer address in checkout), which is
 * a different code path than live rate estimation.
 */
class UbigeoShippingInformationManagementPlugin
{
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ): void {
        $this->applyExtensionAttributes($addressInformation->getShippingAddress());
        $this->applyExtensionAttributes($addressInformation->getBillingAddress());
    }

    private function applyExtensionAttributes(?AddressInterface $address): void
    {
        if ($address === null) {
            return;
        }

        $extensionAttributes = $address->getExtensionAttributes();
        if ($extensionAttributes === null) {
            return;
        }

        foreach ([PeruAddressAttributesManager::CODE_PROVINCIA, PeruAddressAttributesManager::CODE_DISTRITO] as $code) {
            $getter = 'get' . str_replace('_', '', ucwords($code, '_'));
            if (!method_exists($extensionAttributes, $getter)) {
                continue;
            }

            $value = $extensionAttributes->{$getter}();
            if ($value !== null && $value !== '') {
                $address->setData($code, $value);
            }
        }
    }
}
