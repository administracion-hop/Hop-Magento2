// Desactiva mixins/overrides dependientes de Amasty
window.hop = window.hop || {};
window.hop.amasty_checkout_disabled = false;

require.config({
  config: {
    mixins: {
      'Amasty_CheckoutCore/js/model/one-step-layout': {
        'Hop_Envios/js/model/one-step-layout-mixin': true
      },
      'Amasty_CheckoutCore/js/model/shipping-rate-service-override': {
        'Hop_Envios/js/model/amasty-shipping-rate-service-mixin': true
      }
    }
  },
  map: {
    '*': {
      'Amasty_CheckoutCore/js/onepage/shipping/methods':
        'Hop_Envios/js/onepage/shipping/methods'
    }
  }
});
