define([
    "jquery",
    "mage/utils/wrapper",
    "Magento_Checkout/js/model/quote",
    "ko",
    "mage/translate",
], function ($, wrapper, quote, ko, $t) {
    "use strict";

    return function (targetModule) {
        return targetModule.extend({
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
