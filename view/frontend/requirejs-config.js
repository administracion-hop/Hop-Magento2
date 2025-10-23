var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Hop_Envios/js/view/shipping': true
            },
            'Amasty_CheckoutCore/js/model/one-step-layout': {
                'Hop_Envios/js/model/one-step-layout-mixin': true
            }
        }
    },
    map: {
        '*': {
            'Amasty_CheckoutCore/onepage/shipping/methods':
                'Hop_Envios/onepage/shipping/methods'
        }
    }
};