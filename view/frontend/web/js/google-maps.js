define([
    'ko',
    'underscore',
    'jquery',
    'uiComponent',
    'Improntus_Hop/js/google-maps-loader',
    'Magento_Checkout/js/checkout-data' ,
], function (
    ko,
    _,
    $,
    Component,
    GoogleMapsLoader,
    checkoutData
) {
    'use strict';
    var map;
    var myLatitude = '-34.6191538';
    var myLongitude = '-58.4466699';
    var existLatLng = false;

    return {
        init: function (lat = null, lng = null)
        {
            map = new google.maps.Map(document.getElementById('google-maps-hop'), {
                center: this.getLatLngCustomer(lat, lng),
                zoom: 13
            });

            map.addListener('idle', () => {
                var hopPoints = window.checkoutConfig.hop.hop_points;

                if(undefined !== hopPoints && undefined !== hopPoints.data)
                {
                    $('.point').hide();

                    $.each(hopPoints.data, function (index, value){
                        var myLatLng = {
                            lat: parseFloat(value.lat),
                            lng: parseFloat(value.lng)
                        };

                        if(map.getBounds().contains(myLatLng)){
                            $('.point.'+value.id).show();
                        }
                    });
                }
            });

            return map;
        },
        getLatLngCustomer: function(latInit = null, lngInit = null)
        {
            var myLatLng;

            if(latInit && lngInit){
                myLatLng = {
                    lat: parseFloat(latInit),
                    lng: parseFloat(lngInit)
                };
                return myLatLng;
            }

            if(navigator.geolocation)
            {
                navigator.geolocation.getCurrentPosition(this.successPosition, this.errorPosition);
                myLatLng = {
                    lat: parseFloat(myLatitude),
                    lng: parseFloat(myLongitude)
                };
            }
            else
            {
                myLatLng = {
                    lat: parseFloat(myLatitude),
                    lng: parseFloat(myLongitude)
                };
            }
            return myLatLng;
        },
        successPosition: function(position){
            myLongitude = position.coords.longitude;
            myLatitude = position.coords.latitude;

        },
        errorPosition: function(){
            console.log('No es posible la geolocalizacion del cliente.');
        },
        existLatLngCustomer: function(){
            if(navigator.geolocation)
            {
                navigator.geolocation.getCurrentPosition(this.successPositionExist, this.errorPositionExist);
                return existLatLng;
            }
            else
            {
                return false;
            }
        },
        successPositionExist: function(){
            existLatLng = true;
        },
        errorPositionExist: function(){
            existLatLng = false;
        },
    };
});
