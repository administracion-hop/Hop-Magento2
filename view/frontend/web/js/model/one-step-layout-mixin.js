define([], function () {
  'use strict';

  return function (target) {
    var originalGetCheckoutBlock = target.getCheckoutBlock;

    target.getCheckoutBlock = function (blockName) {
      var requestComponent = originalGetCheckoutBlock.call(this, blockName);

      if (blockName === 'shipping_method' && requestComponent()) {
        // Forzar a nuestro template
        requestComponent().template = 'Hop_Envios/onepage/shipping/methods';
      }

      return requestComponent;
    };

    return target;
  };
});
