# Reintentos de despacho

Configuración que lo controla: no tiene claves propias. Las esperas están en la constante `RETRY_DELAYS_MINUTES` de `Model/DispatchError.php`.

## Para qué sirve

Cuando Hop rechaza un despacho, conserva el motivo, le dice al operador cómo resolverlo y reintenta solo si el error es transitorio. Evita que un pedido quede sin despachar sin que nadie lo vea.

## Dónde vive

- Clasificación: `Model/DispatchError.php` (`classify`, `getInstruction`, `isRetryable`, `retryDelayMinutes`).
- Registro del fallo: `Model/HopEnviosRepository.php` (`markFailed`, `markCompleted`, `getRetryableFailed`).
- Reintento automático: `Cron/GenarateShipment.php` (`retryOrder`).
- Reintento manual: `Controller/Adminhtml/Order/Send.php`, `Helper/ShippingMethod.php` (`retryDispatch`).
- Pantalla: `Block/Adminhtml/Order/View/ShippingInfo.php` (`getDispatchInfo`), `view/adminhtml/templates/order/view/shipping-info.phtml`.
- Datos anteriores: `Setup/Patch/Data/RestorePickupShippingDescription.php`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Recibe el texto de error y el código de estado de la respuesta de Hop, si lo hay.

Escribe en `hop_envios`: `status_shipment` en `failed`, `last_error`, `last_error_code`, `attempts` y `next_retry_at`. El motivo no se escribe en la descripción de envío del pedido, porque esa la ve el comprador.

## Comportamiento

1. `markFailed` suma un intento y clasifica el texto. Hop no devuelve códigos, así que busca fragmentos conocidos en el mensaje.
2. `retryDelayMinutes` decide la espera. Si el error es conocido, o el estado es menor a 500, no hay reintento automático.
3. Si no hubo respuesta, o el estado es 500 o mayor, espera 1, 10 y 30 minutos según el intento. Después se frena. Son cuatro intentos en total.
4. Cada minuto, el proceso programado toma los `failed` con `next_retry_at` vencido.
5. Antes de intentar, vacía `next_retry_at`. Si el intento falla, `markFailed` agenda el siguiente; si no llega a despachar nada, queda sin reintento.
6. Si el pedido tiene envíos de Magento, repite `dispatch` con el último. Si no, lo procesa como un pedido pendiente.
7. El panel muestra el estado, el motivo, cómo resolverlo y, cuando corresponde, un enlace a la configuración.
8. El operador corrige y usa "Enviar a HOP". Al despachar bien, `markCompleted` limpia el error y la fecha de reintento.

Instrucciones por tipo de error: código postal de origen, referencia en uso, medidas, valor o peso, documento, nombre o teléfono, punto faltante, punto deshabilitado y configuración faltante.

## Errores y casos límite

- Multibulto parcial: Hop rechaza algunos bultos y acepta otros. Se asigna `multibulto_partial`, que no se puede reintentar, y el panel muestra "Despacho parcial: Hop rechazó bultos" con los botones de etiquetas.
- Rechazo del multibulto completo: no se crean registros de bultos, así que se puede reintentar.
- Respuesta 429 (demasiadas llamadas): no se reintenta solo. Hop pide frenar en ese caso.
- Excepción antes de llegar a Hop: no tiene estado, así que cuenta como transitoria salvo que el texto coincida con un error conocido.
- Pedido cancelado durante los reintentos: no se despacha y queda `failed` sin fecha.

## Qué otras partes se ven afectadas

- [Despacho de pedidos](despacho-de-pedidos.md): todos los caminos terminan en `markFailed` o `markCompleted`.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): `isRetryable` decide qué botones se ven.
- [Conexión con Hop](conexion-con-hop.md): entrega el código de estado.
- Sistema externo: Hop. Un cambio en sus textos de error deja de clasificar esos casos y los trata como desconocidos.

## Decisiones y pendientes

### Decisiones conocidas

- La política de reintentos y los errores conocidos siguen la documentación de Hop (comentario de `DispatchError`).
- Clasificar por fragmentos de texto es deliberado, porque Hop no devuelve códigos. Para sumar un error se agrega una fila en `PATTERNS` y su texto en `INSTRUCTIONS`.
- El reintento se vacía antes de intentar para no repetir cada minuto un caso que no despacha (comentario en `retryOrder`).
- En un multibulto parcial, los bultos aceptados quedan sin seguimiento en el envío de Magento, porque `addTrackToShipment` solo corre si todo salió bien. Es lo esperado, según confirmó el desarrollador.

### Diferencias detectadas

- `docs/multibulto-guia-admin.md` dice que no hay reintento manual del despacho. El botón "Enviar a HOP" reintenta todos los casos salvo el multibulto parcial.
- La misma guía dice que tras un rechazo completo hay que crear el último envío otra vez. El envío ya existe y el camino del código es "Enviar a HOP".
- `attempts` no se reinicia al despachar bien ni con el reintento manual: los intentos a mano también consumen la secuencia de esperas.

### Sin confirmar

- Si la lista de `PATTERNS` sigue al día con los mensajes actuales de Hop.
