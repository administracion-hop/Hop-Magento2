var config = {
    config: {
        mixins: {
            'Magento_Checkout/js/view/shipping': {
                'Hop_Envios/js/view/shipping': true
            },
            'Magento_Checkout/js/action/set-shipping-information': {
                'Hop_Envios/js/action/set-ubigeo-shipping-information-mixin': true
            },
            'Magento_Checkout/js/model/shipping-rate-processor/new-address': {
                'Hop_Envios/js/mixin/ubigeo-new-address-mixin': true
            }
        }
    }
};