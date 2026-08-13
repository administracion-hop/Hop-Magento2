define([
    "underscore",
    "Magento_Checkout/js/model/quote",
    "mage/translate",
    "Magento_Checkout/js/model/address-converter",
    "uiRegistry",
], function (_, quote, $t, addressConverter, registry) {
    "use strict";

    return function (targetModule) {
        return targetModule.extend({
            initialize: function () {
                this._super();

                var hopConfig = window.checkoutConfig.hop;
                if (!hopConfig) {
                    return this;
                }

                var timer;

                /**
                 * Whether a change to the given field code should trigger a rate
                 * re-collection, given the CURRENT country. This is evaluated per change
                 * (not once at init) because the customer can switch country live:
                 * - Outside Peru: only "postcode" matters, same as always.
                 * - Peru + ubigeo_source "field": only the one configured field matters.
                 * - Peru + ubigeo_source "mapping": only region_id + the configured
                 *   distrito/provincia attributes matter - never a plain postcode change.
                 *
                 * @param {String} code
                 * @param {String} countryId
                 * @return {Boolean}
                 */
                function isRelevantChange(code, countryId) {
                    if (countryId !== 'PE') {
                        return code === 'postcode';
                    }

                    if (hopConfig.ubigeo_source === 'mapping') {
                        return code === 'region_id'
                            || code === hopConfig.ubigeo_distrito_attribute
                            || code === hopConfig.ubigeo_provincia_attribute;
                    }

                    return code === hopConfig.ubigeo_field;
                }

                /**
                 * Rebuilds the address from the current form data and reassigns it onto
                 * quote.shippingAddress(), which re-triggers rate collection via the
                 * subscription already set up in Magento_Checkout/js/model/shipping-rate-service.
                 */
                function scheduleRateCollection() {
                    clearTimeout(timer);
                    timer = setTimeout(function () {
                        var provider = registry.get('checkoutProvider');
                        if (!provider) {
                            return;
                        }
                        var addressData = provider.get('shippingAddress');
                        if (addressData && addressData.country_id) {
                            quote.shippingAddress(addressConverter.formAddressDataToQuoteAddress(addressData));
                        }
                    }, 400);
                }

                var candidateCodes = _.uniq(_.compact([
                    'postcode',
                    'region_id',
                    hopConfig.ubigeo_field,
                    hopConfig.ubigeo_distrito_attribute,
                    hopConfig.ubigeo_provincia_attribute
                ]));

                candidateCodes.forEach(function (code) {
                    var componentPath = 'checkout.steps.shipping-step.shippingAddress' +
                        '.shipping-address-fieldset.' + code;

                    registry.async(componentPath)(function (component) {
                        component.value.subscribe(function () {
                            var provider = registry.get('checkoutProvider');
                            var countryId = provider && provider.get('shippingAddress.country_id');

                            if (isRelevantChange(code, countryId)) {
                                scheduleRateCollection();
                            }
                        });
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
