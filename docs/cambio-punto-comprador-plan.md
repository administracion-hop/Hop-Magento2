# Cambio de punto Hop desde «Mis pedidos»: plan de implementación

> Preparado por el equipo de desarrollo de menze · 22/10/2026
> Versión colaborativa: https://claude.ai/artifact/P1y7dyck262PdGnT4kX57f

La tarea es viable con el módulo actual, pero exige dos refactors (cálculo de precio del carrier y selector JS del checkout) y una decisión del PO sobre cómo recotizar antes de empezar.

## Contexto y objetivo

Hoy solo el administrador cambia el punto Hop de un pedido, con el botón «Cambiar punto Hop». El objetivo es que el comprador registrado lo haga desde «Mis pedidos», con el mismo mapa del checkout, mientras el pedido no se despachó a Hop.

Reglas de negocio:

- Solo clientes registrados, sobre sus propios pedidos.
- Solo antes del despacho a Hop, con la misma condición de corte que el admin.
- El nuevo punto se acepta si su cotización es igual a la tarifa de Hop del checkout antes de descuentos, redondeada a 2 decimales. El comprador conserva el descuento que tuvo.
- Una sola cotización, después de elegir el punto. El mapa no cotiza ni filtra.
- Envío gratis (método freeshipping o envío neto 0 por regla de carrito): se acepta cualquier punto sin cotizar.
- Persistencia con `Helper/ShippingMethod.php::addHopData()`, igual que el admin.
- Fuera de alcance: invitados, precotización en el mapa, cambios posteriores al despacho.

## Hallazgos del código actual

| # | Hallazgo | Dónde | Impacto en la tarea |
| --- | --- | --- | --- |
| 1 | La condición de corte del admin no es «sin etiqueta»: oculta el botón si `status_shipment = completed` o si hay un error de multibulto no reintentable. La lógica vive dentro del plugin. | `Plugin/Widget/Context.php` (líneas 101-104) | Extraer a un servicio reutilizable para que admin y frontend usen exactamente la misma condición. |
| 2 | El endpoint `/V2/hop-envios/estimate` no cotiza: solo guarda el punto en sesión. La cotización real está en `collectRates` e incluye medidas, peso, valor `(int)`, `percentage_rate`, `fixed_value` y el umbral `hop_free_shipping`. | `Model/Carrier/Hop.php::collectRates` | Extraer el cálculo a un servicio compartido. Si no se replica, la recotización nunca coincide con lo cobrado. |
| 3 | El guardado del admin recibe por GET nombre, dirección y horario del punto y los guarda tal cual en `shipping_description`. Tampoco valida en servidor que el pedido no esté despachado. | `Controller/Adminhtml/Order/Save.php` | En frontend no se puede copiar: el servidor toma los datos del punto de la lista de Hop, nunca del request. |
| 4 | El selector del checkout está acoplado al carrito (`quote`, `checkoutConfig`, `rateRegistry`). | `view/frontend/web/js/view/hop.js` | Separar el render de lista y mapa en un módulo JS compartido. |
| 5 | `Block/Order/Info.php` y `order/info.phtml` no están conectados a ningún layout. Hoy «Mis pedidos» no muestra nada de Hop. | `Block/Order/Info.php` | Crear el layout `sales_order_view.xml` de frontend. |
| 6 | Git marca `Hop.php`, `composer.json` y `module.xml` como modificados, pero solo cambió el permiso de archivo (755 a 644) por Windows. | Repo del módulo | Correr `git config core.fileMode false` para no ensuciar el PR. |

## Fase 0: decisiones del PO

Nacho respondió el 23/10/2026: cinco de las seis preguntas quedaron cerradas y la 4 necesita una aclaración.

| # | Pregunta | Decisión | Qué implica para dev |
| --- | --- | --- | --- |
| 1 | Si el pedido salió gratis por el umbral propio de Hop (`hop_free_shipping`), ¿cuenta como envío gratis? | Sí | Todo envío neto 0 se acepta sin cotizar: freeshipping de Magento, regla de carrito o umbral de Hop. |
| 2 | Si la tarifa de Hop o las medidas cambiaron desde la compra, ¿se puede cambiar el punto? | No | Se mantiene la regla: la cotización actual se compara contra lo cobrado y, si difiere, se rechaza. No se recotiza el punto original. |
| 3 | ¿Qué monto se compara? | Tal como se cobró en el checkout. Ajuste del 2026-11-03: contra la tarifa antes del descuento | Se compara contra la tarifa de Hop del pedido antes de descuentos (`base_shipping_amount`, o `base_shipping_incl_tax` si la tienda tiene «Shipping Prices include tax»), con el mismo criterio de impuestos y moneda con que el carrier la cotizó. Comparar neto de descuentos rechazaba siempre los pedidos con cupón parcial sobre el envío; Nacho aprobó el cambio. |
| 4 | ¿El comentario del historial es visible para el comprador? | Pendiente: Nacho preguntó «¿qué comentario?» | Es el comentario que exige el criterio de aceptación: punto anterior, punto nuevo y que lo cambió el comprador. Hasta que responda, queda interno. |
| 5 | ¿Se notifica por mail? | No | Sin notificaciones. |
| 6 | ¿Los pedidos en *holded* o *payment_review* pueden cambiar de punto? | Sí | Solo se excluyen `canceled` y `closed`. |

## Fases de implementación

Las fases 1 y 2 son backend puro y se pueden hacer en paralelo. La 3 depende de ambas y la 4 de la 3.

### Fase 1: elegibilidad

Un servicio, por ejemplo `Model/PickupPoint/ChangeEligibility`, decide si un pedido puede cambiar de punto y por qué no.

- Método de envío `hop_hop`.
- Estado distinto de `canceled` y `closed`.
- No despachado, con la lógica `isRecordDispatched` extraída del plugin. El plugin del admin pasa a usar este servicio sin cambiar su comportamiento.
- Para el comprador el corte es más estricto (corrección de la revisión de código del 2026-11-03): tampoco se permite si el cron ya está despachando (`status_shipment = processing`) o si el pedido ya tiene un envío de Magento.
- Pedido del cliente logueado.

Lo consumen el bloque que muestra el link y el controlador que confirma. Riesgo: tocar el plugin del admin; se cubre con un test de regresión.

### Fase 2: recotización y regla de precio

Es la fase más delicada, porque un cambio en el carrier rompe la cotización del checkout.

- Extraer de `collectRates` un servicio, por ejemplo `Model/Shipping/HopPriceCalculator`: recibe el paquete y devuelve el precio ajustado (cotización, porcentaje, valor fijo, `max(0)`). El carrier lo usa sin cambiar su resultado.
- Armar el paquete desde el pedido (medidas, peso, valor), respetando productos configurables y padres igual que el carrier.
- Detectar envío gratis: método freeshipping, o envío neto 0.
- Comparar con `round(x, 2)` contra el envío neto cobrado.
- Si `estimatePrice` devuelve `false` o hay timeout, rechazar con error genérico.

### Fase 3: rutas y controladores de frontend

- `etc/frontend/routes.xml`, que hoy no existe (frontName `hop`, por ejemplo).
- `Order/ChangePoint` (GET): página dentro de «Mis pedidos». El login lo exige `Customer\Controller\AccountInterface`. La pertenencia la valida `OrderViewAuthorization`, que da el mismo 404 para pedido ajeno o inexistente.
- `Order/Points` (GET, JSON): puntos para el CP o ubigeo del pedido, sin aceptar un CP del cliente. Reutiliza `Webservice::getPickupPoints` y no cotiza. Si el pedido no es elegible responde sin puntos: pedido ajeno o inexistente → «No encontramos el pedido.»; pedido propio despachado → «ya fue despachado»; otro motivo → «no permite cambiar el punto» (revisión del 2026-11-03).
- Límite de 10 confirmaciones por pedido y sesión. Solo gastan intento las que realmente cotizaron con Hop (el servicio lo informa): los rechazos previos a la cotización y los cambios con envío gratis no cuentan.
- `Order/ChangePointPost` (POST con `form_key`, JSON). Exige el `form_key` siempre, también en peticiones AJAX, que Magento por defecto deja pasar sin él; si falta o no coincide responde «La página expiró. Recargala e intentá de nuevo.» (pruebas en Magento limpio, 2026-11-03). Valida en este orden:
    1. Login.
    2. Pertenencia.
    3. Elegibilidad. Si se despachó mientras el selector estaba abierto, responde «ya fue despachado».
    4. El punto existe en la lista de Hop para ese CP.
    5. No es el mismo punto que ya tiene.
    6. Envío gratis: acepta. Si no, una cotización y la comparación.
    7. Si todo da bien: `addHopData()` con datos del servidor y comentario en el historial (punto anterior, punto nuevo, «cambiado por el comprador»). El pedido se guarda una sola vez, dentro de `addHopData()`; el comentario se guarda aparte, con el repositorio del historial, para no volver a disparar los observers del guardado del pedido (limpieza del 2026-11-04). No toca `shipping_amount` ni `grand_total`.

Detalle a verificar: `addHopData()` guarda el pedido y eso dispara `SalesOrderSaveAfter`; no debe provocar un despacho accidental.

### Fase 4: UI de frontend

- `view/frontend/layout/sales_order_view.xml`: link «Cambiar punto de retiro» en el detalle del pedido, solo si es elegible.
- Layout de la página nueva sobre `customer_account`.
- JS: refactorizar el render de lista y mapa de `hop.js` a un módulo compartido que el checkout siga usando igual.
- Componente nuevo: marca el punto actual, deshabilita «Elegir» en él, hace el POST y muestra el resultado en la misma pantalla. Tras un rechazo, el comprador elige otro sin recargar.
- Si el cambio se confirma, lleva al detalle del pedido con el mensaje «Listo, cambiamos el punto de retiro de tu pedido.». El POST se envía sin disparar la recarga de mensajes de Magento (`global: false`); si no, el mensaje se consume antes de llegar al pedido (pruebas en Magento limpio, 2026-11-03).
- Textos: otro costo de envío, error genérico, ya despachado.
- Estilos: reutilizar `_module.less`.

### Fase 5: tests

- Unitarios: elegibilidad, envío gratis, comparación con redondeo (igual, mayor, menor), calculadora de precio con los mismos resultados que el carrier antes del refactor.
- Controlador POST: pedido ajeno, sin login, despachado entre apertura y confirmación, mismo punto, punto inexistente, error de API.
- Regresión: plugin del admin y `collectRates` del checkout.

### Fase 6: QA manual en local

El entorno local ya tiene AR, PE y UY, productos de prueba y credenciales sandbox. Uruguay sirve solo cuando Hop desbloquee el seller `MENZESBX`.

- Pedido Hop normal, con regla de envío gratis y con freeshipping.
- Pedido ya despachado (forzando `status_shipment`).
- Pedido de otro cliente.
- Cambio desde el admin y checkout completo, como regresión.

### Fase 7: calidad, PR y deploy

- Auditorías de código, seguridad y performance.
- PR en el repo del módulo y suba de versión en `module.xml` y `composer.json`.
- Deploy a DEV.

## Criterios de aceptación por fase

| Criterio | Fase | Verificación |
| --- | --- | --- |
| Link visible si: logueado, pedido propio, Hop, sin etiqueta | 1, 4 | Unit (elegibilidad) + QA |
| No visible con etiqueta Hop generada | 1 | Unit |
| No visible con otro método de envío | 1 | Unit |
| No visible en cancelados o cerrados | 1 | Unit |
| No logueado redirigido al login | 3 | Unit (controlador) + QA |
| Mismo mapa del checkout, punto actual identificado | 4 | QA |
| El mapa no cotiza al cargar ni al navegar | 3, 4 | QA (logs de Hop) |
| No se puede confirmar el mismo punto | 3, 4 | Unit (controlador) + QA |
| Una sola cotización al elegir, sin envío gratis | 2, 3 | Unit |
| Confirma si cotización = envío neto, redondeo a 2 | 2 | Unit |
| Rechaza si es mayor o menor, pedido sin cambios | 2, 3 | Unit |
| Mensaje «otro costo de envío» al rechazar | 4 | QA |
| Elegir otro punto tras un rechazo sin salir | 4 | QA |
| Error o timeout de cotización: rechazo y mensaje genérico | 2, 3 | Unit |
| Freeshipping de Magento: acepta sin cotizar | 2 | Unit |
| Envío neto 0 por regla de carrito: acepta sin cotizar | 2 | Unit |
| Guarda con `addHopData()`, visible en front y admin | 3 | QA |
| Comentario en historial con punto anterior y nuevo | 3 | Unit + QA |
| No cambia total ni monto de envío | 3 | Unit |
| Validaciones en servidor al confirmar | 3 | Unit (controlador) |
| Etiqueta generada entre apertura y confirmación: rechazo «ya despachado» | 3 | Unit |
| Pedido ajeno: misma respuesta que inexistente | 3 | Unit + QA |
| Botón del admin sin cambios | 1 | Unit (regresión) + QA |

## Riesgos, tamaño y próximos pasos

El tamaño «Mediano» es correcto; la carga y el riesgo se concentran en las fases 2 y 4.

| Riesgo | Mitigación |
| --- | --- |
| Regresión en la cotización del checkout al extraer el cálculo del carrier | Test que compare precios antes y después del refactor, más QA manual del checkout en AR y PE |
| Regresión en el selector del checkout al extraer el render de `hop.js` | QA manual del checkout con y sin Amasty OSC |
| Tarifa o medidas cambiadas desde la compra: ningún punto coincide | Decidido: se rechaza el cambio y el comprador ve el mensaje de otro costo (fase 0, respuesta 2) |
| Carrera con el cron de despacho (corre cada minuto) | Revalidar elegibilidad en el POST justo antes de guardar, bloqueando también los pedidos que el cron está despachando (`processing`) o que ya tienen envío de Magento |
| **Riesgo conocido y aceptado (2026-11-03):** margen de milisegundos entre que el cron lee el pedido y lo marca `processing`. Si el comprador confirma justo ahí, Hop recibe el punto anterior y Magento muestra el nuevo. Lo mismo con un envío manual del admin en ese instante | No se cierra en esta tarea: requiere bloqueos en base de datos dentro del cron de despacho y del observer de envíos, con riesgo de frenar todos los despachos. Se detecta por el comentario interno del pedido (punto anterior y nuevo) y soporte lo corrige con Hop. Si llega a ocurrir, abrir una tarea aparte |
| Hop no responde: las llamadas a Hop no tienen tiempo de espera propio y usan los 300 segundos por defecto de Magento | Problema general del módulo, ya existía (checkout, lista de puntos, despacho). En el cambio de punto, pasado ese tiempo se rechaza con el mensaje genérico y el pedido no cambia. Se deja anotado; si se quiere acortar, tarea aparte sobre `Webservice` |
| Inyección de texto en `shipping_description` | Datos del punto tomados de la lista de Hop en servidor |

Próximos pasos:

- [x] Enviar las preguntas de la fase 0 al PO
- [ ] Aclararle a Nacho a qué comentario se refiere la pregunta 4
- [ ] Correr `git config core.fileMode false` en el repo del módulo
- [ ] Arrancar fases 1 y 2 en paralelo
