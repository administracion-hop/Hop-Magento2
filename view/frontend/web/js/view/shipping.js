/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define(
    [
        'jquery',
        'underscore',
        'Magento_Ui/js/form/form',
        'ko',
        'Magento_Customer/js/model/customer',
        'Magento_Customer/js/model/address-list',
        'Magento_Checkout/js/model/address-converter',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/action/create-shipping-address',
        'Magento_Checkout/js/action/select-shipping-address',
        'Magento_Checkout/js/model/shipping-rates-validator',
        'Magento_Checkout/js/model/shipping-address/form-popup-state',
        'Magento_Checkout/js/model/shipping-service',
        'Magento_Checkout/js/action/select-shipping-method',
        'Magento_Checkout/js/model/shipping-rate-registry',
        'Magento_Checkout/js/action/set-shipping-information',
        'Magento_Checkout/js/model/step-navigator',
        'Magento_Ui/js/modal/modal',
        'Magento_Checkout/js/model/checkout-data-resolver',
        'Magento_Checkout/js/checkout-data',
        'uiRegistry',
        'mage/translate',
        'Magento_Checkout/js/model/shipping-rate-service'
    ],
    function (
        $,
        _,
        Component,
        ko,
        customer,
        addressList,
        addressConverter,
        quote,
        createShippingAddress,
        selectShippingAddress,
        shippingRatesValidator,
        formPopUpState,
        shippingService,
        selectShippingMethodAction,
        rateRegistry,
        setShippingInformationAction,
        stepNavigator,
        modal,
        checkoutDataResolver,
        checkoutData,
        registry,
        $t
    ) {
        'use strict';

        var mixin = {
            nextButtonVisible: ko.observable(false),

            validateShippingInformationHopSucursal: function ()
            {
                var hopSucursalRadio = $('.table-checkout-shipping-method')
                    .find('input[type="radio"]:checked')
                    .val();

                if(hopSucursalRadio === 'hop_hop')
                {
                    if(!window.checkoutConfig.quoteData.hop_data)
                    {
                        alert('Seleccione una sucursal Hop para continuar');
                        return false;
                    }
                    else
                    {
                        return true;
                    }
                }
                else
                {
                    return true;
                }
            },
            setShippingInformation: function () {
                if (this.validateShippingInformation() && this.validateShippingInformationHopSucursal()) {
                    setShippingInformationAction().done(
                        function () {
                            stepNavigator.next();
                        }
                    );
                }
            }
        };

        return function (target) {
            return target.extend(mixin);
        };
    }
);
