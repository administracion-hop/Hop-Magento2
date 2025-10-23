define(["jquery", "Magento_Checkout/js/model/quote"], function ($, quote) {
    "use strict";

    return function (target) {
        return $.extend(target, {
            forceUpdateRates: function () {
                if (typeof target.updateRates === "function") {
                    target.updateRates(quote.shippingAddress(), true);
                }

                return this;
            },
        });
    };
});
