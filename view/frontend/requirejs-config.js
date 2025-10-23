var hop_amasty_mixin_enabled = !window.hop.amasty_checkout_disabled,
    config;

var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Hop_Envios/js/view/shipping': true
            },
            'Amasty_CheckoutCore/js/model/one-step-layout': {
                'Hop_Envios/js/model/one-step-layout-mixin': hop_amasty_mixin_enabled
            },
            'Amasty_CheckoutCore/js/model/shipping-rate-service-override': {
                'Hop_Envios/js/model/amasty-shipping-rate-service-mixin': hop_amasty_mixin_enabled
            }
        }
    },
    'map': { '*': {} },
/*     map: {
        '*': {
            'Amasty_CheckoutCore/onepage/shipping/methods':
                'Hop_Envios/onepage/shipping/methods'
        }
    } */
};

if (hop_amasty_mixin_enabled) {
    config.map['*'] = {
        'Amasty_CheckoutCore/onepage/shipping/methods': 'Hop_Envios/onepage/shipping/methods'
    };
}