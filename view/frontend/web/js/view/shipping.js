define([
    "Magento_Checkout/js/model/quote",
    "mage/translate",
    "Magento_Checkout/js/model/address-converter",
    "Magento_Checkout/js/model/shipping-rate-service",
    "uiRegistry",
], function (quote, $t, addressConverter, rateService, registry) {
    "use strict";

    return function (targetModule) {
        return targetModule.extend({
            initialize: function () {
                this._super();
                var ubigeoField = window.checkoutConfig.hop && window.checkoutConfig.hop.ubigeo_field;
                if (!ubigeoField) {
                    return this;
                }

                var componentPath = 'checkout.steps.shipping-step.shippingAddress' +
                    '.shipping-address-fieldset.' + ubigeoField;

                registry.async(componentPath)(function (component) {
                    var timer;
                    component.value.subscribe(function () {
                        clearTimeout(timer);
                        timer = setTimeout(function () {
                            var provider = registry.get('checkoutProvider');
                            if (!provider) { return; }
                            var addressData = provider.get('shippingAddress');
                            if (addressData && addressData.country_id) {
                                rateService.estimateShippingMethods(
                                    addressConverter.formAddressDataToQuoteAddress(addressData)
                                );
                            }
                        }, 400);
                    });
                });

                return this;
            },

            validateShippingInformation: function () {
                let result = this._super();
                if (result && quote.shippingMethod()) {
                    if (quote.shippingMethod().carrier_code == "hop") {
                        if (!window.checkoutConfig.quoteData.hop_data) {
                            this.errorValidationMessage(
                                $t("Seleccione una sucursal Hop para continuar")
                            );
                            return false;
                        }
                        return true;
                    }
                }
                return result;
            },
        });
    };
});
