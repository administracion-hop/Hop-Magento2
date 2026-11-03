/*browser:true*/
/*global define*/
/**
 * Renders one Hop pickup point of the points list (#points-maps-hop). Shared by the checkout
 * modal (view/hop.js) and the buyer's pickup point change in My Orders (view/change-point.js).
 *
 * Moved from view/hop.js (generarContenidoLista): the markup and data-* attributes are what
 * both selectors read when a point is chosen. Hop data is HTML-escaped (code review 2026-11-03).
 */
define(
    [
        'jquery',
        'underscore',
        'Hop_Envios/js/google-maps'
    ],
    function ($, _, GoogleMaps) {
        'use strict';

        /**
         * Hop data goes into HTML text and attributes: escape it so a name or address with
         * <, >, & or quotes can neither break the markup nor inject script. Same output as
         * before for ordinary text, and the data-* attributes still read back the raw value.
         *
         * @param {*} text
         * @return {string}
         */
        function esc(text) {
            return String(text)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#39;");
        }

        return function generarContenidoLista(value, listado) {
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

                html_horarios += '<p>' + esc(val['day_description']) + ' de ' + esc(hourFrom) + ' a ' + esc(hourTo) + '</p>';

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

                html_horarios = '<p>'+ esc(primerDia.day_description) + ' a ' + esc(ultimoDia.day_description) + ' de ' + esc(hourFrom) + ' a ' + esc(hourTo) + '</p>';
                horarios = primerDia.day_description + ' a ' + ultimoDia.day_description + ' de ' + hourFrom + ' a ' + hourTo + '. ';

                $(diasDistintos).each(function (i, val)
                {
                    var hourFrom = val.hour_from.slice(0, -3);
                    var hourTo = val.hour_to.slice(0, -3);

                    html_horarios += '<p>'+ esc(val.day_description) + ' de ' + esc(hourFrom) + ' a ' + esc(hourTo) + '</p>';
                    horarios += val.day_description + ' de ' + hourFrom + ' a ' + hourTo;
                });
            }

            $('#horarios-punto-hop div').html(html_horarios);
            listado = listado + '<div class="point '+ esc(value.id) + '">';
            listado = listado + '<a href="#"><p class="title-sucursal-hop"><strong>' + esc(value.reference_name) + '</strong></p></a>';
            listado = listado + '<p class="direccion-sucursal-hop">' + esc(value.full_address) + ', ' + esc(value.city) + ', ' + esc(value.state) + '</p>';
            if (GoogleMaps.existLatLngCustomer()) {
                var latLngCustomer = GoogleMaps.getLatLngCustomer();
                listado = listado + '<a class="como-llegar" href="javascript:void(0)"'
                    + ' onclick="window.open(\'https://www.google.com.ar/maps/dir/' + parseFloat(latLngCustomer.lat) + ',' + parseFloat(latLngCustomer.lng) + '/' + parseFloat(value.lat) + ',' + parseFloat(value.lng) + '/\', \'_blank\');" '
                    + ' data-referencename="' + esc(value.reference_name) + '" '
                    + ' data-schedules="' + esc(horarios) + '" '
                    + ' data-seller="' + esc(value.seller) + '" '
                    + ' data-postcode="' + esc(value.zip_code) + '"'
                    + ' data-id="' + esc(value.id) + '"'
                    + ' data-name="' + esc(value.name) + '"'
                    + ' data-address="' + esc(value.full_address) + '"'
                    + ' data-providercode="' + esc(value.provider_code) + '"'
                    + ' data-distributorid="' + esc(value.distributor_id) + '"'
                    + ' data-agencycode="' + esc(value.agency_code) + '"'
                    + ' >Como llegar</a>';
            }
            listado = listado + '<p><div class="horarios"><div class="dias">' + html_horarios + '</div></div></p>';
            // listado = listado + '<p><a href="javascript:void(0)" onclick="var w = window.open(\'\',\'popupWindow\',\'width=300,height=300,top=100%,left=100%,scrollbars=yes\');w.document.title = \'Horarios\';var $w = jQuery(w.document.body);$w.html(jQuery(this).parent().parent().find(\'.horarios\').html())">Horarios</a></p>';
            listado = listado + '<button class="btn-select-hop"'
                + ' data-referencename="' + esc(value.reference_name) + '" '
                + ' data-schedules="' + esc(horarios) + '" '
                + ' data-seller="' + esc(value.seller) + '" '
                + ' data-postcode="' + esc(value.zip_code) + '"'
                + ' data-id="' + esc(value.id) + '"'
                + ' data-name="' + esc(value.name) + '"'
                + ' data-address="' + esc(value.full_address) + '"'
                + ' data-providercode="' + esc(value.provider_code) + '"'
                + ' data-distributorid="' + esc(value.distributor_id) + '"'
                + ' data-agencycode="' + esc(value.agency_code) + '"'
                + ' >Elegir</button>';
            listado = listado + '</div>';
            return listado;
        };
    }
);
