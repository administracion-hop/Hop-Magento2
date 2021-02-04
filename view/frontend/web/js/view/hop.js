/*browser:true*/
/*global define*/
define(
    [
        'ko',
        'uiComponent',
        'jquery',
        'underscore',
        'jquery/validate',
        'mage/calendar',
        'Magento_Ui/js/modal/modal',
        'Improntus_Hop/js/google-maps',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/shipping-rate-processor/new-address',
        'Magento_Checkout/js/model/shipping-rate-processor/customer-address',
        'Magento_Checkout/js/model/shipping-rate-registry',
        'Magento_Checkout/js/action/select-shipping-method'
    ],
    function (
        ko,
        Component,
        $,
        _,
        validate,
        calendar,
        modal,
        GoogleMaps,
        quote,
        defaultProcessor,
        customerAddressProcessor,
        rateRegistry,
        selectShippingMethodAction
    ) {
        'use strict';
        var map;
        var activeInfoWindow;
        function generarContenidoLista(value, listado) {
            var html_horarios = '';
            var schedules = _.sortBy(value.schedules,'day_code',function(num) {
                return num;
            });

            var horarios = '';
            var diasIguales = [];
            var diasDistintos = [];
            var j = 0;
            var k = 0;

            $(schedules).each(function (i, val) {
                var hourFrom = val['hour_from'].slice(0, -3);
                var hourTo = val['hour_to'].slice(0, -3);

                html_horarios += '<p>' + val['day_description'] + ' de ' + hourFrom + ' a ' + hourTo + '</p>';

                if(i === 0){
                    horarios += val['day_description'] + ', ' + hourFrom + ' a ' + hourTo;
                    diasIguales[j] = [];
                    diasIguales[j]['day_description'] = schedules[i].day_description;
                    diasIguales[j]['hour_from'] = schedules[i].hour_from;
                    diasIguales[j]['hour_to'] = schedules[i].hour_to;
                    j++;
                }
                else{
                    horarios += ' - ' + val['day_description'] + ' de ' + hourFrom + ' a ' + hourTo;

                    if(schedules[i].hour_from === schedules[i-1].hour_from && schedules[i].hour_to === schedules[i-1].hour_to)
                    {
                        diasIguales[j] = [];
                        diasIguales[j]['day_description'] = schedules[i].day_description;
                        diasIguales[j]['hour_from'] = schedules[i].hour_from;
                        diasIguales[j]['hour_to'] = schedules[i].hour_to;
                        j++;
                    }
                    else{
                        diasDistintos[k] = [];
                        diasDistintos[k]['day_description'] = schedules[i].day_description;
                        diasDistintos[k]['hour_from'] = schedules[i].hour_from;
                        diasDistintos[k]['hour_to'] = schedules[i].hour_to;
                        k++;
                    }
                }
            });

            if(diasIguales.length > 1)
            {
                var primerDia = diasIguales[0];
                var ultimoDia = diasIguales.pop();
                var hourFrom = primerDia.hour_from.slice(0, -3);
                var hourTo = primerDia.hour_to.slice(0, -3);

                html_horarios = '<p>'+ primerDia.day_description + ' a ' + ultimoDia.day_description + ' de ' + hourFrom + ' a ' + hourTo + '</p>';
                horarios = primerDia.day_description + ' a ' + ultimoDia.day_description + ' de ' + hourFrom + ' a ' + hourTo + '. ';

                $(diasDistintos).each(function (i, val)
                {
                    var hourFrom = val.hour_from.slice(0, -3);
                    var hourTo = val.hour_to.slice(0, -3);

                    html_horarios += '<p>'+ val.day_description + ' de ' + hourFrom + ' a ' + hourTo + '</p>';
                    horarios += val.day_description + ' de ' + hourFrom + ' a ' + hourTo;
                });
            }

            $('#horarios-punto-hop div').html(html_horarios);
            listado = listado + '<div class="point '+ value.id + '">';
            listado = listado + '<a href="#"><p class="title-sucursal-hop"><strong>' + value.reference_name + '</strong></p></a>';
            listado = listado + '<p class="direccion-sucursal-hop">' + value.full_address + ', ' + value.city + ', ' + value.state + '</p>';
            if (GoogleMaps.existLatLngCustomer()) {
                var latLngCustomer = GoogleMaps.getLatLngCustomer();
                listado = listado + '<a class="como-llegar" href="javascript:void(0)"'
                    + ' onclick="window.open(\'https://www.google.com.ar/maps/dir/' + latLngCustomer.lat + ',' + latLngCustomer.lng + '/' + value.lat + ',' + value.lng + '/\', \'_blank\');" '
                    + ' data-referencename="' + value.reference_name + '" '
                    + ' data-schedules="' + horarios + '" '
                    + ' data-seller="' + value.seller + '" '
                    + ' data-postcode="' + value.zip_code + '"'
                    + ' data-id="' + value.id + '"'
                    + ' data-name="' + value.name + '"'
                    + ' data-address="' + value.full_address + '"'
                    + ' data-providercode="' + value.provider_code + '"'
                    + ' data-distributorid="' + value.distributor_id + '"'
                    + ' data-agencycode="' + value.agency_code + '"'
                    + ' >Como llegar</a>';
            }
            listado = listado + '<p><div class="horarios"><div class="dias">' + html_horarios + '</div></div></p>';
            // listado = listado + '<p><a href="javascript:void(0)" onclick="var w = window.open(\'\',\'popupWindow\',\'width=300,height=300,top=100%,left=100%,scrollbars=yes\');w.document.title = \'Horarios\';var $w = jQuery(w.document.body);$w.html(jQuery(this).parent().parent().find(\'.horarios\').html())">Horarios</a></p>';
            listado = listado + '<a class="btn-select-hop" href="javascript:void(0)"'
                + ' onclick="jQuery(this).addClass(\'selected-point-hop\');'
                + ' jQuery(\'#select-hop-point\').trigger(\'click\');" '
                + ' data-referencename="' + value.reference_name + '" '
                + ' data-schedules="' + horarios + '" '
                + ' data-seller="' + value.seller + '" '
                + ' data-postcode="' + value.zip_code + '"'
                + ' data-id="' + value.id + '"'
                + ' data-name="' + value.name + '"'
                + ' data-address="' + value.full_address + '"'
                + ' data-providercode="' + value.provider_code + '"'
                + ' data-distributorid="' + value.distributor_id + '"'
                + ' data-agencycode="' + value.agency_code + '"'
                + ' >Elegir</a>';
            listado = listado + '</div>';
            return listado;
        }
        return Component.extend({
            defaults: {
                template: 'Improntus_Hop/hop-map',
                options: {}
            },

            nombre: ko.observable(''),
            initialize: function () {
                this._super();
            },
            initMap: function () {
                map = GoogleMaps.init();
            },
            getHopPoints: function () {
                $.ajax('/rest/V1/improntus/hop_points',
                    {
                        method: 'GET',
                        context: this,
                        success: function (response) {
                            var points = JSON.parse(response);
                            var bounds = new google.maps.LatLngBounds();
                            var infowindow = new google.maps.InfoWindow();
                            var i = 0;
                            var listado = '';
                            if (points) {
                                window.checkoutConfig.hop.hop_points = points;
                                $.each(points.data, function (key, value) {
                                    var myLatLng = {
                                        lat: parseFloat(value.lat),
                                        lng: parseFloat(value.lng)
                                    };

                                    var marker = new google.maps.Marker({
                                        position: myLatLng,
                                        map: map,
                                        title: value.full_address,
                                        icon: window.checkoutConfig.hop.hop_icon
                                    });

                                    marker.addListener("click", () => {
                                        var pointSelected = '.point.'+value.id;
                                        $('.point').removeClass('point-selected');
                                        $(pointSelected).addClass('point-selected');
                                        $(pointSelected).prependTo('#points-maps-hop');

                                        $('#points-maps-hop').animate({
                                            scrollTop: (0)
                                        }, 'slow');
                                    });

                                    bounds.extend(marker.position);

                                    var horarios = '';
                                    $(value.schedules).each(function (i, val) {
                                        if(i === 0){
                                            horarios += val['day_description'] + ', ' + val['hour_from'] + ' a ' + val['hour_to'];
                                        }else{
                                            horarios += ' - ' + val['day_description'] + ', ' + val['hour_from'] + ' a ' + val['hour_to'];
                                        }
                                    });

                                    var contentString = '<div class="infowindow-hop">'
                                        + '<strong>' + value.reference_name + '</strong>'
                                        + '<br>'
                                        + '<br>'
                                        + '<a href="javascript:void(0)"'
                                        + ' onclick="jQuery(this).addClass(\'selected-point-hop\');'
                                        + ' jQuery(\'#select-hop-point\').trigger(\'click\');" '
                                        + ' data-referencename="' + value.reference_name + '" '
                                        + ' data-schedules="' + horarios + '" '
                                        + ' data-seller="' + value.seller + '" '
                                        + ' data-postcode="' + value.zip_code + '"'
                                        + ' data-id="' + value.id + '"'
                                        + ' data-name="' + value.name + '"'
                                        + ' data-address="' + value.full_address + '"'
                                        + ' data-providercode="' + value.provider_code + '"'
                                        + ' data-distributorid="' + value.distributor_id + '"'
                                        + ' data-agencycode="' + value.agency_code + '"'
                                        + ' >Elegir</a>'
                                        + '</div>';

                                    var infowindow = new google.maps.InfoWindow({
                                        content: contentString
                                    });

                                    (function (marker, myLatLng) {
                                        google.maps.event.addListener(marker, "click", function (e) {
                                            if (activeInfoWindow) {
                                                activeInfoWindow.close();
                                            }
                                            infowindow.open(map, marker);
                                            activeInfoWindow = infowindow;
                                        });
                                    })(marker, myLatLng);

                                    i++;

                                    listado = generarContenidoLista(value, listado);
                                });

                                /*Aqui no porque va completo*/
                                $('#points-maps-hop').html(listado);
                            } else {
                                let charactersList = document.getElementById('points-maps-hop');
                                $(charactersList).html('<div class="point"><p>No se encuentran puntos HOP</p></div>');
                            }
                        },
                        error: function (e, status) {
                            let charactersList = document.getElementById('points-maps-hop');
                            $(charactersList).html('<div class="point"><p>No se encuentran puntos HOP</p></div>');
                            console.log(e);
                            console.log(status);
                        }
                    });

                var options = {
                    type: 'popup',
                    responsive: true,
                    innerScroll: true,
                    title: 'Elegí el punto HOP donde queres retirar tu compra',
                    modalClass: 'hop-modal-checkout',
                };

                var popup = modal(options, $('#hop-popup-modal'));

                $("#hop-popup-modal").modal("openModal");
            },
            selectHopPoint: function () {
                var selectedPointHop = $('.selected-point-hop');
                var shippingForm = $('#co-shipping-method-form');
                localStorage.setItem('hopPointIsSelect', 0);

                $("#hop-popup-modal").modal("closeModal");

                shippingForm.find('input').attr('checked', false);
                shippingForm.find('input[value=hop_hop]').attr('checked', 'hop_hop').trigger('click');
                selectShippingMethodAction('hop_hop');

                var hopData = {
                    'hopPointReferenceName': selectedPointHop.attr('data-referencename'),
                    'hopPointSchedules': selectedPointHop.attr('data-schedules'),
                    'hopPointPostcode': selectedPointHop.attr('data-postcode'),
                    'hopPointSeller': selectedPointHop.attr('data-seller'),
                    'hopPointId': selectedPointHop.attr('data-id'),
                    'hopPointName': selectedPointHop.attr('data-name'),
                    'hopPointAddress': selectedPointHop.attr('data-address'),
                    'hopPointProvidercode': selectedPointHop.attr('data-providercode'),
                    'hopPointDistributorId': selectedPointHop.attr('data-distributorid'),
                    'hopPointAgencycode': selectedPointHop.attr('data-agencycode')
                };

                var processors = [];
                $('#points-maps-hop').addClass('loading-hop');

                $.ajax('/rest/V1/improntus/hop_estimate',
                    {
                        method: 'GET',
                        context: this,
                        data: hopData,
                        dataType: 'json',
                        contentType: 'application/json',
                        success: function (response) {
                            rateRegistry.set(quote.shippingAddress().getCacheKey(), null);

                            processors.default = defaultProcessor;
                            processors['customer-address'] = customerAddressProcessor;

                            var type = quote.shippingAddress().getType();

                            if (processors[type]) {
                                processors[type].getRates(quote.shippingAddress());
                            } else {
                                processors.default.getRates(quote.shippingAddress());
                            }

                            if ($('#hopsucursal-sucursal').length > 0) {
                                $('#hopsucursal-sucursal').val(1);
                                localStorage.setItem('hopPointIsSelect', 1);
                            }

                            $('#points-maps-hop').removeClass('loading-hop');
                        },
                        error: function (e, status) {
                            console.log(e);
                            console.log(status);
                        }
                    });
            },
            mostrarLista: function () {
                let btnVerMas = $('.ver-mas-hop');
                let lista = $('#points-maps-hop');
                btnVerMas.slideUp('slow');
                lista.slideDown('slow');
            },
            closeLista: function () {
                let lista = $('#points-maps-hop');
                let btnVerMas = $('.ver-mas-hop');
                lista.slideUp('slow');
                btnVerMas.slideDown('slow');
            },
            updateHopPoints: function (pointsUpdateListado) {
                this.setCenterHopFromPoints(pointsUpdateListado);

                var points = pointsUpdateListado;
                var bounds = new google.maps.LatLngBounds();
                var infowindow = new google.maps.InfoWindow();
                var i = 0;
                var listado = '';
                let city = 'city';
                let provincia = 'state';

                $.each(points, function (key, value) {
                    var myLatLng = {
                        lat: parseFloat(value.lat),
                        lng: parseFloat(value.lng)
                    };

                    var marker = new google.maps.Marker({
                        position: myLatLng,
                        map: map,
                        title: value.full_address,
                        icon: window.checkoutConfig.hop.hop_icon
                    });

                    bounds.extend(marker.position);

                    var contentString = '<div class="infowindow-hop">'
                        + '<strong>' + value.reference_name + '</strong>'
                        /*+ '<br>'
                        + value.full_address + ', ' + value.city + ', ' + value.state */
                        + '<br>'
                        + '<br>'
                        + '<a href="javascript:void(0)"'
                        + ' onclick="jQuery(this).addClass(\'selected-point-hop\');'
                        + ' jQuery(\'#select-hop-point\').trigger(\'click\');" '
                        + ' data-referencename="' + value.reference_name + '" '
                        + ' data-seller="' + value.seller + '" '
                        + ' data-postcode="' + value.zip_code + '"'
                        + ' data-id="' + value.id + '"'
                        + ' data-name="' + value.name + '"'
                        + ' data-address="' + value.full_address + '"'
                        + ' data-providercode="' + value.provider_code + '"'
                        + ' data-distributorid="' + value.distributor_id + '"'
                        + ' data-agencycode="' + value.agency_code + '"'
                        + ' >Elegir</a>'
                        + '</div>';

                    var infowindow = new google.maps.InfoWindow({
                        content: contentString
                    });

                    (function (marker, myLatLng) {
                        google.maps.event.addListener(marker, "click", function (e) {
                            if (activeInfoWindow) {
                                activeInfoWindow.close();
                            }
                            infowindow.open(map, marker);
                            activeInfoWindow = infowindow;
                        });
                    })(marker, myLatLng);
                    i++;
                });

                var options = {
                    type: 'popup',
                    responsive: true,
                    innerScroll: true,
                    modalClass: 'hop-modal-checkout',
                };
            },
            setCenterHopFromPoints: function (pointsUpdateListado) {
                var points = pointsUpdateListado;
                var latCenter = '0';
                var LngCenter = '0';
                var polygonCoords = [];
                var bounds = new google.maps.LatLngBounds();

                if (points.length === 1) {
                    $.each(points, function (key, value) {
                        latCenter = value.lat;
                        LngCenter = value.lng;
                    });
                } else {
                    $.each(points, function (key, value) {
                        polygonCoords.push(new google.maps.LatLng(value.lat, value.lng));
                    });

                    $.each(polygonCoords, function (key, value) {
                        bounds.extend(value);
                    });

                    var latlng = bounds.getCenter();

                    map = GoogleMaps.init();
                    map.setCenter(latlng);

                }
            },
            displayCharacters: function (elements, characterList) {
                var listado = "";
                $.each(elements, function (key, value) {
                    listado = generarContenidoLista(value, listado);
                });
                characterList.innerHTML = listado ;
            },
            buscarElementos: function (data, event) {
                let charactersList = document.getElementById('points-maps-hop');
                let searchString = event.target.value.toLowerCase();
                let hpCharacters = window.checkoutConfig.hop.hop_points;
                if (hpCharacters) {
                    const filteredCharacters = hpCharacters.data.filter((character) => {
                        return (
                            character.full_address.toLowerCase().includes(searchString) ||
                            character.city.toLowerCase().includes(searchString) ||
                            character.state.toLowerCase().includes(searchString)
                        );
                    });
                    if(filteredCharacters.length == 0){
                        charactersList.innerHTML= '<div class="point"><p>No se encuentran resultados</p></div>';
                    }else{
                        this.displayCharacters(filteredCharacters,charactersList);
                    }
                } else {
                    $(charactersList).html('<div class="point"><p>No se encuentran puntos HOP</p></div>');
                }
            }
        });
    }
);
