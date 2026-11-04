/*browser:true*/
/*global define*/
/**
 * Pickup point change in My Orders: the checkout's map and points list, for an order.
 *
 * Lists points only — no price is quoted while browsing. The one quote happens server side,
 * when the buyer confirms a point (hop/order/changepointpost).
 */
define(
    [
        'jquery',
        'underscore',
        'mage/translate'
    ],
    function ($, _, $t) {
        'use strict';

        return function (config, element) {
            var $root = $(element);
            var $list = $root.find('#points-maps-hop');
            var $message = $root.find('.hop-change-point-message');
            var points = [];
            var currentPointId = null;
            var renderPoint = null;
            var map = null;
            var activeInfoWindow = null;
            var locked = false;
            // A confirmation is in flight: nothing can be chosen until it answers, or the buyer
            // could fire a second change and end up on a point other than the one shown.
            var busy = false;

            /**
             * @return {boolean} whether the "Elegir" buttons must be disabled right now
             */
            function isBlocked() {
                return locked || busy;
            }

            /**
             * Point ids come from Hop's JSON (any type) and from data-* attributes (always
             * strings): compare them as trimmed, case-insensitive strings. Empty never matches.
             *
             * @param {*} a
             * @param {*} b
             * @return {boolean}
             */
            function sameId(a, b) {
                var normalize = function (id) {
                    return id === null || id === undefined ? '' : String(id).trim().toLowerCase();
                };
                return normalize(a) !== '' && normalize(a) === normalize(b);
            }

            // google-maps-loader reads the API key from here outside the checkout.
            window.hopConfig = {
                api_key: config.apiKey,
                hop_icon: config.iconUrl
            };

            function showMessage(type, text) {
                $message.html(
                    $('<div>', {'class': 'message message-' + type + ' ' + type, role: 'alert'})
                        .append($('<div>').text(text))
                );
            }

            function setBusy(value) {
                busy = value;
                $list.toggleClass('loading-hop', busy);
                $list.find('.btn-select-hop').prop('disabled', isBlocked());
                markCurrentPoint();
            }

            /**
             * The order's current point can't be chosen again.
             */
            function markCurrentPoint() {
                if (!currentPointId) {
                    return;
                }
                $list.find('.btn-select-hop').filter(function () {
                    return sameId($(this).attr('data-id'), currentPointId);
                }).prop('disabled', true).text($t('Punto actual'))
                    .closest('.point').addClass('point-selected point-current');
            }

            function renderList(items) {
                var listado = '';

                // The renderer module loads after the points arrive: until then there is nothing
                // to draw (the buyer may already be typing in the search box).
                if (!renderPoint) {
                    return;
                }
                if (!items.length) {
                    $list.html('<div class="point"><p>' + $t('No se encuentran puntos HOP') + '</p></div>');
                    return;
                }
                _.each(items, function (value) {
                    listado = renderPoint(value, listado);
                });
                $list.html(listado);
                markCurrentPoint();
                // The current point first, so the buyer sees where the order is today.
                $list.find('.point-current').prependTo($list);
                if (isBlocked()) {
                    $list.find('.btn-select-hop').prop('disabled', true);
                }
            }

            function renderMarkers(GoogleMaps) {
                var bounds;

                map = GoogleMaps.init();
                bounds = new google.maps.LatLngBounds();

                _.each(points, function (value) {
                    var marker = new google.maps.Marker({
                        position: {lat: parseFloat(value.lat), lng: parseFloat(value.lng)},
                        map: map,
                        title: value.full_address,
                        icon: config.iconUrl
                    });
                    var isCurrent = sameId(value.id, currentPointId);
                    var infowindow = new google.maps.InfoWindow({
                        content: $('<div>', {'class': 'infowindow-hop'})
                            .append($('<strong>').text(value.reference_name))
                            .append('<br><br>')
                            .append(
                                $('<button>', {type: 'button', 'data-id': value.id, disabled: isCurrent || locked})
                                    .text(isCurrent ? $t('Punto actual') : $t('Elegir'))
                            )[0]
                    });

                    marker.addListener('click', function () {
                        if (activeInfoWindow) {
                            activeInfoWindow.close();
                        }
                        // The popup is built once: refresh its button for the current state.
                        $(infowindow.getContent()).find('button').prop('disabled', isCurrent || isBlocked());
                        infowindow.open(map, marker);
                        activeInfoWindow = infowindow;
                        $list.find('.point').removeClass('point-selected');
                        $list.find('.btn-select-hop').filter(function () {
                            return sameId($(this).attr('data-id'), value.id);
                        }).closest('.point').addClass('point-selected').prependTo($list);
                        $list.animate({scrollTop: 0}, 'slow');
                    });
                    bounds.extend(marker.getPosition());
                });

                if (points.length) {
                    map.setCenter(bounds.getCenter());
                    if (points.length > 1) {
                        map.fitBounds(bounds);
                    }
                }
            }

            function choose(pointId) {
                if (isBlocked() || !pointId || sameId(pointId, currentPointId)) {
                    return;
                }

                $message.empty();
                setBusy(true);
                if (activeInfoWindow) {
                    activeInfoWindow.close();
                }

                // global: false keeps Magento from reloading the "messages" section after this
                // request, which would consume the success message before the order page shows it.
                $.ajax(config.saveUrl, {
                    method: 'POST',
                    dataType: 'json',
                    global: false,
                    data: {
                        'order_id': config.orderId,
                        'point_id': pointId,
                        'form_key': config.formKey
                    }
                }).done(function (response) {
                    if (response.success) {
                        window.location.href = config.orderViewUrl;
                        return;
                    }
                    // Nothing else can be done from here for these: keep the list locked.
                    if (_.contains(['dispatched', 'not_found', 'not_eligible', 'too_many_attempts', 'login_required'], response.code)) {
                        locked = true;
                    }
                    showMessage('error', response.message);
                    setBusy(false);
                    if (locked) {
                        $list.find('.btn-select-hop').prop('disabled', true);
                    }
                }).fail(function () {
                    showMessage('error', $t('No pudimos confirmar el cambio en este momento. Intentá de nuevo más tarde o contactá a atención al cliente.'));
                    setBusy(false);
                });
            }

            $root.on('click', '.btn-select-hop, .infowindow-hop button', function (event) {
                event.preventDefault();
                choose($(this).attr('data-id'));
            });

            $root.on('click', '.title-sucursal-hop', function (event) {
                event.preventDefault();
            });

            $root.on('keyup', '#buscador-hop', function () {
                var search = $(this).val().toLowerCase();

                renderList(_.filter(points, function (point) {
                    return (point.full_address || '').toLowerCase().indexOf(search) !== -1
                        || (point.city || '').toLowerCase().indexOf(search) !== -1
                        || (point.state || '').toLowerCase().indexOf(search) !== -1;
                }));
            });

            setBusy(true);
            $.ajax(config.pointsUrl, {method: 'GET', dataType: 'json'}).done(function (response) {
                if (!response.success) {
                    locked = true;
                    showMessage('error', response.message || $t('No encontramos el pedido.'));
                    $list.removeClass('loading-hop');
                    return;
                }

                points = response.points || [];
                currentPointId = response.currentPointId;

                // The map is required only now: google-maps-loader needs window.hopConfig.
                require(
                    ['Hop_Envios/js/google-maps-loader', 'Hop_Envios/js/google-maps', 'Hop_Envios/js/model/point-renderer'],
                    function (mapsLoaded, GoogleMaps, pointRenderer) {
                        renderPoint = pointRenderer;
                        renderList(points);
                        setBusy(false);
                        mapsLoaded.done(function () {
                            renderMarkers(GoogleMaps);
                        });
                    }
                );
            }).fail(function () {
                showMessage('error', $t('No pudimos cargar los puntos HOP. Intentá de nuevo más tarde.'));
                $list.removeClass('loading-hop');
            });
        };
    }
);
