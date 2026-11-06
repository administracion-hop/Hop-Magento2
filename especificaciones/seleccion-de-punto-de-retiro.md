# Selección de punto de retiro

Configuración que lo controla: `shipping/hop/api_key`, `shipping/hop/use_customer_taxvat`, `customer/create_account/vat_frontend_visibility`.

## Para qué sirve

Permite que el comprador elija en un mapa o en un listado el punto de Hop donde va a retirar su compra. Conserva esa elección durante la compra y la pasa al pedido. También ajusta el campo del documento del comprador, que Hop necesita para el despacho.

## Dónde vive

- Servicios web: `Api/PickupPointManagementInterface.php`, `Model/PickupPointManagement.php` (`get`, `estimate`, `getSelectedPoint`, `persistPickupPointToDb`) y `etc/webapi.xml`.
- Interfaz de compra: `view/frontend/web/js/view/hop.js`, `view/frontend/web/js/view/shipping.js`, `view/frontend/web/js/google-maps.js`, `view/frontend/web/js/google-maps-loader.js`, `view/frontend/web/template/hop-map.html`, `view/frontend/web/template/hop-shipping-item.html`, `view/frontend/templates/head.phtml`, `view/frontend/layout/checkout_index_index.xml`.
- Datos para la compra: `Model/ConfigProvider.php`, `Plugin/DefaultConfigProvider.php`.
- Paso del carrito al pedido: `Observer/QuoteSubmitSuccessObserver.php`, `Observer/ClearHopDataAfterOrderPlace.php`.
- Registros del punto: `Model/QuotePickupPointRepository.php`, `Model/OrderPickupPointRepository.php`.
- Campo del documento: `Plugin/Checkout/LayoutProcessorPlugin.php`, `Plugin/Customer/ViewModel/AddressPlugin.php`.
- Permisos del mapa en el navegador: `etc/csp_whitelist.xml`.
- Migraciones: `Setup/Patch/Data/MigrateHopData.php`, `Setup/Patch/Data/MigrateQuotePickupPointsToOrderPickupPoints.php`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Servicios web, todos con acceso anónimo y por `GET`:

| Ruta | Qué hace |
| --- | --- |
| `/V1/hop-envios/points/:zipCode` y `/V2/hop-envios/points/:zipCode/:countryCode` | Devuelve los puntos. Acepta `regionId`, `provincia` y `distrito` para Perú |
| `/V1/hop-envios/estimate` y `/V2/hop-envios/estimate` | Guarda el punto elegido en la sesión y en la base de datos |
| `/V1/hop-envios/selected-point` y `/V2/hop-envios/selected-point` | Devuelve el punto elegido para el carrito actual |

Efectos: escribe la sesión `hop_data`, `hop_envios_selected_pickup_point` (carrito) y `hop_envios_order_pickup_point` (pedido).

## Comportamiento

1. Al cargar la compra, `DefaultConfigProvider` entrega la clave de Google Maps y, si el carrito ya tiene punto, lo deja disponible para la pantalla.
2. Si Hop tiene tarifa disponible, la fila del método muestra el botón para elegir un punto.
3. El botón abre una ventana con el mapa y el listado. La ruta incluye el código de la tienda para que Magento resuelva el carrito correcto.
4. El buscador filtra el listado por el texto que escribe el comprador.
5. Al elegir un punto, la pantalla selecciona el método `hop_hop` y llama a `estimate`.
6. `estimate` filtra los parámetros contra una lista permitida, los guarda en la sesión y escribe el registro del carrito con la descripción "Retirá tu pedido en: ...".
7. La pantalla vuelve a pedir las tarifas, que ahora incluyen el precio del punto. Ver [Cotización de envío](cotizacion-de-envio.md).
8. Si cambia el código postal, la pantalla descarta el punto. En Perú solo compara el código postal cuando el campo de ubigeo configurado es el propio código postal.
9. Si no hay punto en pantalla, consulta `selected-point` y lo recupera si coincide con el código postal actual.
10. Para avanzar con Hop hace falta un punto: si no hay, se muestra "Seleccione una sucursal Hop para continuar".
11. Al crearse el pedido, un observador copia el registro del carrito a `hop_envios_order_pickup_point`, y otro limpia la sesión.

Campo del documento: cuando `vat_frontend_visibility` está activo, el campo `vat_id` se muestra como "DNI" (documento nacional de identidad) en la dirección de envío y en la de facturación. Si `use_customer_taxvat` está activo, también lo agrega cuando falta y lo marca obligatorio en el formulario de direcciones de la cuenta.

## Errores y casos límite

- `estimate` sin `hopPointPostcode`: responde `success: false` y no guarda nada.
- Falla al guardar en la base de datos: se ignora sin registrar nada, porque la sesión ya quedó escrita.
- `get` con código `0` y sin ubigeo resuelto: devuelve una lista vacía. El `0` es el valor que manda la pantalla cuando no tiene código postal.
- Error al guardar el punto: solo se escribe en la consola del navegador y el indicador de carga no se quita.
- Error al copiar el punto al pedido: se registra en el log y el pedido queda sin punto, lo que después hace fallar el despacho.
- Tarifa no disponible: la pantalla descarta el punto elegido.

## Qué otras partes se ven afectadas

- [Cotización de envío](cotizacion-de-envio.md): lee y borra el mismo registro del carrito.
- [Sincronización de puntos](sincronizacion-de-puntos.md): de ahí salen los datos que se muestran.
- [Despacho de pedidos](despacho-de-pedidos.md): usa el punto del pedido.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): cambia el punto usando el mismo servicio de puntos.
- [Compatibilidad con Amasty](compatibilidad-con-amasty.md): reemplaza la plantilla de métodos y fuerza la actualización de tarifas.
- [Direcciones de Perú](direcciones-de-peru.md): resuelve el código de búsqueda.
- Sistema externo: Google Maps.

## Decisiones y pendientes

### Decisiones conocidas

- La versión 1 de las rutas se conserva por compatibilidad; la versión 2 lleva el código de tienda, porque sin él Magento resuelve siempre la tienda por defecto (comentario en `etc/webapi.xml`).
- La sesión se respalda en la base de datos para que `collectRates` recupere el punto si la sesión se pierde (comentario en `persistPickupPointToDb`).
- La validación de punto obligatorio también aplica con Amasty, según confirmó el desarrollador.

### Diferencias detectadas

- Se dijo que `hop_envios_selected_pickup_point` no se usa. Es la tabla activa del carrito, a través de `QuotePickupPoint`. Lo que no usa nadie es la clase duplicada `SelectedPickupPoint` y su repositorio, que apuntan a la misma tabla.
- Se dijo que los puntos se muestran ordenados por cercanía. El módulo no ordena los puntos.
- `estimate` guarda datos aunque es una ruta `GET` anónima, y no verifica que el identificador del punto exista en Hop.
- Las columnas `hop_data` de `quote` y `sales_order` no las escribe ningún código actual; solo las lee `MigrateHopData`.
- El caso `req` de `LayoutProcessorPlugin` nunca se alcanza: `IsVAtShowToFrontend` devuelve un booleano y el `switch` entra siempre por `opt`. El campo "DNI" del proceso de compra nunca queda obligatorio por este camino.
- El largo máximo del documento es 8 en la dirección de envío y 20 en la de facturación.

### Sin confirmar

- Si Hop devuelve los puntos ya ordenados por cercanía.
