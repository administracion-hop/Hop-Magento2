# Despacho de pedidos

Configuración que lo controla: `carriers/hop/active`, `shipping/hop/status_allowed`, `shipping/hop/seller_code`, `shipping/hop/storage_code`, `shipping/hop/shipping_type`, `shipping/hop/type_label`, `shipping/hop/label_size`, `shipping/hop/days_offset`, `shipping/hop/size_category`, `shipping/hop/validate_client_id`, `shipping/hop/use_customer_taxvat`, `shipping/hop/customer_document_attribute`, `shipping/hop/origin_zipcode`, `shipping/hop/measure_attribute_alto`, `shipping/hop/measure_attribute_largo`, `shipping/hop/measure_attribute_ancho`.

## Para qué sirve

Avisa a Hop que un pedido sale, para que cree el envío y devuelva el número de seguimiento y la etiqueta. Hop recibe la lista de bultos una sola vez, así que el módulo espera a que el pedido esté completo antes de avisar.

## Dónde vive

- Despacho al guardar un envío de Magento: `Observer/SalesOrderShipmentSaveAfter.php` (`execute`, `dispatch`, `removeDuplicateHopTracks`, `addTrackToShipment`).
- Registro del pedido como pendiente: `Observer/SalesOrderSaveAfter.php`.
- Despacho directo y reintento: `Helper/ShippingMethod.php` (`createShipmentData`, `retryDispatch`).
- Botón "Enviar a HOP": `Controller/Adminhtml/Order/Send.php`.
- Proceso programado: `Cron/GenarateShipment.php`, registrado como `hop_envios_genarate_shipment` en `etc/crontab.xml`, cada minuto.
- Llamadas a Hop: `Model/Webservice.php` (`createShipping`, `createShippingMultibulto`, `findShipmentByReferenceId`).
- Paquete: `Helper/Data.php` (`getPackageData`, `getPackageDataForShipment`).
- Registros: `Model/HopEnviosRepository.php` (tabla `hop_envios`), `Model/HopEnviosShipmentRepository.php` (tabla `hop_envios_shipment`).
- Datos anteriores: `Setup/Patch/Data/BackfillStatusShipmentCompleted.php`.
- Guía para el operador: `docs/multibulto-guia-admin.md`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Recibe un pedido con el método `hop_hop` y su punto en `hop_envios_order_pickup_point`.

Envía a Hop los datos del destinatario, del remitente, del punto y del paquete. La referencia es `<código de vendedor>-<número de pedido>`.

Escribe:

- `hop_envios`: un registro por pedido, con `info_hop` (la respuesta de Hop, en JSON, un formato de texto para datos) y `status_shipment` (`pending`, `processing`, `completed` o `failed`).
- `hop_envios_shipment`: un registro por bulto, con seguimiento, etiqueta y estado (`completed`, `failed` o `unsupported`).
- Una fila de seguimiento en cada envío de Magento.

## Comportamiento

Hay cuatro formas de despachar:

| Forma | Quién la dispara | Bultos | Crea envío en Magento |
| --- | --- | --- | --- |
| Botón "Enviar" con todos los artículos | Operador | 1 | Sí |
| Botón "Enviar" varias veces, con artículos parciales | Operador | Uno por envío | Sí |
| Botón "Enviar a HOP" | Operador | 1 | No |
| Automático, por estado del pedido | Proceso programado | 1 | Sí |

Al guardar un envío de Magento (`dispatch`):

1. Sale si el método no está activo o el pedido no usa `hop_hop`.
2. Quita las filas de seguimiento repetidas del envío.
3. Si quedan artículos sin enviar, sale: el aviso a Hop lo hace el último envío.
4. Crea el registro de `hop_envios` si no existe.
5. Calcula qué envíos todavía no tienen registro. Si no queda ninguno, sale.
6. Si el pedido ya se despachó por "Enviar a HOP", reutiliza ese seguimiento y no llama a Hop.
7. Si ya hay registros de bultos, los envíos nuevos se marcan `unsupported`, sin seguimiento, porque Hop no permite sumar bultos.
8. Con un solo envío llama a `createShipping`; con varios, a `createShippingMultibulto`, con un paquete por envío.
9. Si sale bien, marca `completed`, guarda los registros, agrega el seguimiento y pide la etiqueta. Ver [Etiquetas](etiquetas.md).

Automático:

1. Cada vez que se guarda un pedido cuyo estado está en `status_allowed`, `SalesOrderSaveAfter` crea el registro `pending`. No llama a Hop.
2. El proceso programado toma los registros `pending`. Si el pedido no se puede enviar o ya tiene envíos, no interviene.
3. Si no, marca `processing`, crea un envío con todo lo pendiente y lo guarda, lo que dispara `dispatch`.
4. Pone el pedido en estado `processing`, notifica al comprador y relee el registro. Si quedó `failed` lo deja así; si no, lo marca `completed`.

"Enviar a HOP": si el pedido tiene envíos parciales sin terminar, muestra un error. Si no, llama a `retryDispatch`, que despacha directo cuando no hay envíos de Magento y repite `dispatch` cuando los hay.

Paquete: suma el alto y toma el mayor largo y el mayor ancho, multiplicados por la cantidad. El peso va en gramos. `size_category` solo se envía si falta alguna medida. El tipo de documento enviado es siempre el mismo, y el número sale del `vat_id` de facturación o de la columna de `sales_order` configurada.

## Errores y casos límite

- Hop rechaza el envío: se marca `failed`. Ver [Reintentos de despacho](reintentos-de-despacho.md).
- Pedido sin punto: `createShipping` devuelve `false` y queda `failed` con "No Hop Data".
- Respuesta "reference id ya está en uso" en un envío simple: busca el envío por referencia y lo acepta si coinciden el correo del comprador y el precio.
- Error después de que Hop aceptó, por ejemplo en la etiqueta: no deshace el despacho.
- Pedido con envíos parciales cargados a mano: el proceso automático no interviene, y el registro puede quedar `pending` hasta que se complete el último envío.
- Si el proceso se interrumpe con el registro en `processing`, nada lo retoma.
- Una excepción al crear el envío en el proceso automático devuelve el registro a `pending`, salvo que ya esté `failed`.

## Qué otras partes se ven afectadas

- [Reintentos de despacho](reintentos-de-despacho.md): comparte `dispatch`, `markFailed` y el proceso programado.
- [Etiquetas](etiquetas.md): depende de los registros de bultos.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): los botones dependen de `status_shipment`.
- [Seguimiento para el comprador](seguimiento-para-el-comprador.md): usa `info_hop`.
- [Notificaciones de estado](notificaciones-de-estado.md): usa la referencia para encontrar el pedido.
- [Direcciones de Perú](direcciones-de-peru.md): el despacho simple envía el ubigeo como código postal.
- Sistema externo: Hop. Es el camino crítico del negocio: un error acá deja pedidos sin despachar o duplicados en Hop.

## Decisiones y pendientes

### Decisiones conocidas

- El despacho sale con el último envío porque Hop crea un envío por referencia y no tiene forma de sumar bultos (comentario en `dispatch`).
- El guardado del pedido solo registra `pending`: despachar ahí competiría con el flujo de bultos, porque Magento pasa el pedido a `processing` con el primer envío parcial (comentario en `createShipmentData`).
- El proceso programado usa una transacción nueva por pedido, porque una compartida volvía a guardar los pedidos anteriores y repetía su despacho (comentario en `processOrder`).
- El proceso automático avisa al comprador aunque Hop haya rechazado el despacho. Es aceptado, según confirmó el desarrollador.

### Diferencias detectadas

- La guía dice que "Enviar a HOP" no crea envío en Magento. Es cierto en el botón, pero si falla por un error transitorio el reintento automático pasa por `processOrder` y sí crea uno.
- `createShippingMultibulto` no envía el código postal de destino, ni el del remitente, ni el ubigeo. `createShipping` sí los envía.
- En el despacho simple, `getPackageData` suma el precio unitario sin multiplicar por la cantidad; `getPackageDataForShipment`, que usa multibulto, sí multiplica.
- `createShippingMultibulto` llama a `getStoreCountry` sin la tienda del pedido.
- La recuperación por referencia solo existe en el envío simple, y compara el precio con el costo de envío del pedido, que puede diferir de lo que cobra Hop si hay ajustes o envío gratis.

### Sin confirmar

- Si Hop necesita los códigos postales y el ubigeo en multibulto. Afecta a Perú. Pendiente de revisar a futuro, según indicó el desarrollador.
- Cuál es el valor declarado correcto en el despacho simple. Pendiente, según indicó el desarrollador.
