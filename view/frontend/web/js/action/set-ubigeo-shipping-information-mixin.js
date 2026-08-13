define([
    'jquery',
    'mage/utils/wrapper',
    'Magento_Checkout/js/model/quote'
], function ($, wrapper, quote) {
    'use strict';

    return function (setShippingInformationAction) {
        return wrapper.wrap(setShippingInformationAction, function (originalAction) {
            copyCustomAttributesToExtensionAttributes(quote.shippingAddress());
            copyCustomAttributesToExtensionAttributes(quote.billingAddress());

            return originalAction();
        });
    };

    /**
     * Mirrors customAttributes onto extension_attributes so the values reach
     * Magento\Checkout\Api\Data\ShippingInformationInterface regardless of whether
     * Magento\Quote\Model\Quote\Address\CustomAttributeListInterface recognizes the code.
     *
     * @param {Object} address
     */
    function copyCustomAttributesToExtensionAttributes(address) {
        if (!address) {
            return;
        }

        if (address['extension_attributes'] === undefined) {
            address['extension_attributes'] = {};
        }

        if (address.customAttributes !== undefined) {
            $.each(address.customAttributes, function (key, value) {
                if ($.isPlainObject(value)) {
                    if (key !== undefined && !isNaN(key)) {
                        key = value['attribute_code'];
                    }
                    value = value['value'];
                }
                address['extension_attributes'][key] = value;
            });
        }
    }
});
