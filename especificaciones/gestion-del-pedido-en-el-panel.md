# Gestión del pedido en el panel

Configuración que lo controla: `carriers/hop/active`, `shipping/hop/status_allowed`.

## Para qué sirve

Le da al operador lo que necesita dentro del pedido de Magento: ver el punto y el estado del despacho, cambiar el punto, despachar y bajar etiquetas. También agrega el menú del módulo en el panel.

## Dónde vive

- Botones: `Plugin/Widget/Context.php` (`afterGetButtonList`), declarado en `etc/di.xml` y en `etc/adminhtml/di.xml`.
- Bloque de información: `Block/Adminhtml/Order/View/ShippingInfo.php`, `view/adminhtml/templates/order/view/shipping-info.phtml`.
- Cambio de punto: `Controller/Adminhtml/Order/View.php`, `Controller/Adminhtml/Order/Save.php`, `Block/Adminhtml/Order/HopSelectorView.php`, `Helper/ShippingMethod.php` (`addHopData`), `view/adminhtml/templates/order/select-view.phtml`, `view/adminhtml/templates/order/view/select-js.phtml`.
- Confirmación de "Enviar a HOP": `Block/Adminhtml/Order/View/SendToHopJS.php`, `view/adminhtml/templates/order/view/send-js.phtml`.
- Armado de la pantalla: `view/adminhtml/layout/sales_order_view.xml`, `view/adminhtml/layout/default.xml`, `view/adminhtml/web/css/hop.css`.
- Rutas y menú: `etc/adminhtml/routes.xml`, `etc/adminhtml/menu.xml`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Lee `hop_envios`, `hop_envios_shipment` y `hop_envios_order_pickup_point`.

El cambio de punto escribe `pickup_point_id` y `sales_order.shipping_description`. El punto original elegido por el comprador no se modifica.

## Comportamiento

Botones, solo en la vista de un pedido con método `hop_hop`:

| Situación del pedido | Botones |
| --- | --- |
| Sin despachar, sin punto | "Cambiar punto Hop" |
| Sin despachar, con punto | "Cambiar punto Hop" y "Enviar a HOP" |
| Despachado, con varios registros de bultos | "Etiquetas HOP" |
| Despachado, con uno o ningún registro de bulto | "Descargar etiqueta HOP" y "Estado HOP" |

El pedido cuenta como despachado si `status_shipment` es `completed` o si es un multibulto parcial.

Bloque de información: muestra el estado del despacho, el motivo y cómo resolverlo si falló, el punto actual, el del proceso de compra y la descripción original. Ver [Reintentos de despacho](reintentos-de-despacho.md).

Cambio de punto:

1. El botón abre una ventana con los puntos del código postal del pedido, o de su ubigeo si corresponde.
2. Los puntos se piden al servicio web de la tienda del pedido.
3. La ventana advierte si la acción va a crear un envío en Hop, según si el estado del pedido está en `status_allowed`.
4. Al elegir, `addHopData` reescribe la descripción del envío y actualiza el punto. Guardar el pedido puede dejarlo `pending` para el despacho automático.

## Errores y casos límite

- Sin puntos o con error en la consulta: la ventana muestra "No se encuentran puntos HOP".
- Pedido sin dirección de envío: la ruta de puntos queda vacía.
- Etiqueta en formato ZPL (lenguaje de impresoras de etiquetas): el botón abre la dirección del archivo.
- Despachado sin dirección de etiqueta: no se muestra ningún botón.

## Qué otras partes se ven afectadas

- [Despacho de pedidos](despacho-de-pedidos.md): el botón "Enviar a HOP" y el guardado del pedido.
- [Reintentos de despacho](reintentos-de-despacho.md): `isRetryable` cambia los botones.
- [Etiquetas](etiquetas.md): los botones de descarga.
- [Sincronización de puntos](sincronizacion-de-puntos.md): la lista de puntos.
- [Direcciones de Perú](direcciones-de-peru.md): el código de búsqueda.

## Decisiones y pendientes

### Decisiones conocidas

- `status_shipment` decide los botones porque `info_hop` no se completa en multibulto (comentario en `afterGetButtonList`).
- El punto no se puede cambiar después del despacho desde el panel: el botón deja de mostrarse.

### Diferencias detectadas

- El complemento de botones está declarado dos veces con nombres distintos, así que en el panel corre dos veces. El resultado no cambia porque los botones usan los mismos identificadores.
- `etc/adminhtml/menu.xml` usa los recursos `Hop_Envios::settings` y `Hop_Envios::carriers`, que no están en `etc/acl.xml`.
- Los enlaces del menú apuntan a `webservice_hop` y `hop_bicicleta`, que no coinciden con los grupos `hop` de `etc/adminhtml/system.xml`.
- Los controladores `Send`, `Save`, `View` y `Labels` no definen permiso propio, así que usan el permiso general del panel.
- El formulario de cambio de punto guarda por `GET`.
- `isActive` y `getStatusOrderAllowed` se leen sin la tienda del pedido en los botones y en la advertencia.
- La ventana de cambio de punto se titula "Enviar HOP", y la confirmación de "Enviar a HOP" habla de una "acción custom de envíos".

### Sin confirmar

- Si los operadores que no son superadministradores llegan a las pantallas del menú del módulo.
