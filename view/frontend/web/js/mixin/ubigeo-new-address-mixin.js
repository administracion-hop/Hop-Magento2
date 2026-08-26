define([
    'mage/utils/wrapper'
], function (wrapper) {
    'use strict';

    /**
     * Keep in sync with Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager::CODE_*.
     */
    var ATTRIBUTE_CODES = ['hop_ubigeo_provincia', 'hop_ubigeo_distrito'];

    return function (target) {
        target.getRates = wrapper.wrapSuper(target.getRates, function (address) {
            copyExtensionAttributesToCustomAttributes(address);

            return this._super(address);
        });

        return target;
    };

    /**
     * Magento\Quote\Model\ShippingMethodManagement::extractAddressData() strips
     * extension_attributes before applying the address data (unset() call right before
     * addData()), so only customAttributes reaches collectRates() during live rate
     * estimation. Mirror the ubigeo address attributes there in case they only landed on
     * extensionAttributes.
     *
     * @param {Object} address
     */
    function copyExtensionAttributesToCustomAttributes(address) {
        if (!address || !address.extensionAttributes) {
            return;
        }

        ATTRIBUTE_CODES.forEach(function (code) {
            var value = address.extensionAttributes[code];

            if (value === undefined || value === null || value === '') {
                return;
            }

            if (!address.customAttributes) {
                address.customAttributes = {};
            }

            if (!address.customAttributes[code]) {
                address.customAttributes[code] = value;
            }
        });
    }
});
