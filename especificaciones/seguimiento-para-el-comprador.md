# Seguimiento para el comprador

Configuración que lo controla: `carriers/hop/title`.

## Para qué sirve

Le permite al comprador ver desde su cuenta cómo va su envío, con un enlace a la página pública de seguimiento de Hop.

## Dónde vive

- Bloque del pedido: `Block/Order/Info.php` (`getTrackingUrlStatus`), que reemplaza `Magento\Sales\Block\Order\Info` por una preferencia en `etc/di.xml`.
- Plantilla: `view/frontend/templates/order/info.phtml`.
- Seguimiento de Magento: `Model/Carrier/Hop.php` (`isTrackingAvailable`, `getTrackingInfo`).

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Lee `hop_envios.info_hop` y el número de seguimiento de cada envío. Muestra un enlace; no escribe nada.

## Comportamiento

1. En el detalle del pedido, el bloque busca el registro de `hop_envios` y toma `tracking_nro` de `info_hop`.
2. Si el pedido usa `hop_hop` y hay número, muestra el enlace "Estado Envio Hop" debajo del método de envío.
3. En la pantalla de seguimiento de Magento, `getTrackingInfo` entrega el título del método y la dirección de la página de Hop para cada número.

## Errores y casos límite

- Sin registro o sin número: no se muestra el enlace y el resto de la pantalla no cambia.
- Pedido virtual: no se muestra la sección de envío.

## Qué otras partes se ven afectadas

- [Despacho de pedidos](despacho-de-pedidos.md): decide si `info_hop` queda completo.
- La preferencia reemplaza el bloque para todos los pedidos de la tienda, sean o no de Hop. Otro módulo que reemplace el mismo bloque entraría en conflicto.
- Sistema externo: la página pública de seguimiento de Hop.

## Decisiones y pendientes

### Decisiones conocidas

- Ninguna registrada.

### Diferencias detectadas

- En multibulto, `info_hop` no se completa, así que el comprador no ve el enlace en su pedido. Las filas de seguimiento de cada envío sí existen.
- El comentario en `getTrackingInfo` dice que la dirección es de otro servicio y que hay que reemplazarla; el valor ya es el de Hop.
- La plantilla escribe el texto del enlace fijo, sin pasar por traducción.

### Sin confirmar

- Si es aceptable que un pedido multibulto no muestre el enlace. El desarrollador cree que no, pero no está seguro.
