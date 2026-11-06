# Cotización de envío

Configuración que lo controla: `carriers/hop/active`, `carriers/hop/title`, `carriers/hop/description`, `carriers/hop/hop_free_shipping`, `carriers/hop/percentage_rate`, `carriers/hop/fixed_value`, `carriers/hop/showmethod`, `carriers/hop/max_package_weight`, `shipping/hop/origin_zipcode`, `shipping/hop/seller_code`, `shipping/hop/size_category`, `shipping/hop/measure_attribute_alto`, `shipping/hop/measure_attribute_largo`, `shipping/hop/measure_attribute_ancho`.

## Para qué sirve

Ofrece Hop como método de envío en el proceso de compra y calcula cuánto paga el comprador. Combina el precio que devuelve Hop con los ajustes que define la tienda: envío gratis, porcentaje y monto fijo.

## Dónde vive

- Método de envío: `Model/Carrier/Hop.php` (`collectRates`, `isZipCodeRequired`, `getMeasure`, `cleanQuoteData`).
- Consulta del precio y del vendedor: `Model/Webservice.php` (`estimatePrice`, `isSellerActive`).
- Atributos de medidas de los productos: `Setup/Patch/Data/HopAttributes.php`.
- Valores por defecto: `etc/config.xml`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Recibe el pedido de tarifas de Magento (`RateRequest`): artículos, peso total, código postal y país de destino, y la marca de envío gratis de las reglas de promoción. También lee el punto elegido desde la sesión o desde `hop_envios_selected_pickup_point`.

Devuelve una tarifa con el código de transportista `hop` y el método `hop`, que en el pedido queda como `hop_hop`, o un error de tarifa, o nada.

Efectos: guarda o borra el punto elegido en `hop_envios_selected_pickup_point` y escribe en el log del módulo el detalle de cada cotización.

## Comportamiento

1. Si el método no está activo, no devuelve tarifa.
2. Fija la tienda en `Webservice` y consulta si el vendedor está activo. Esta consulta se hace en cada cálculo de tarifas.
3. Resuelve el destino. Con ubigeo configurado usa el código de la dirección y, si no lo hay, el código postal cuando es numérico. Sin ubigeo solo usa un código postal numérico. Ver [Direcciones de Perú](direcciones-de-peru.md).
4. Recupera el punto elegido: primero de la sesión y, si no está, de la base de datos.
5. Calcula las medidas: suma el alto y toma el mayor largo y el mayor ancho. Cada medida del artículo se multiplica por su cantidad. Salta los productos configurables para no contarlos dos veces.
6. Si el peso supera el máximo configurado, devuelve un error de tarifa.
7. Busca puntos para el destino. Ver [Sincronización de puntos](sincronizacion-de-puntos.md).
8. Si la regla de promoción da envío gratis, o el total llega al importe de `hop_free_shipping`, el precio es cero y no se consulta a Hop.
9. Si no, pide el precio a Hop con el código postal de origen, el de destino, el vendedor, el punto si existe y el paquete. El peso va en gramos y el valor como entero.
10. Aplica el ajuste: multiplica por `percentage_rate` y después suma `fixed_value`, que puede ser negativo. El resultado nunca baja de cero.
11. Si el código postal del punto elegido no coincide con el destino, borra el punto.
12. Si queda un punto válido, usa su descripción como título del método y lo guarda para el carrito.

## Errores y casos límite

- Vendedor inactivo o consulta fallida: Hop no aparece.
- Sin destino utilizable: la respuesta queda sin tarifas, salvo con Amasty, donde se agrega el método sin precio. Ver [Compatibilidad con Amasty](compatibilidad-con-amasty.md).
- Peso excedido: siempre muestra el mensaje de error, aunque `showmethod` esté apagado, y borra el punto del carrito.
- Sin puntos o sin precio: borra el punto. Si `showmethod` está encendido muestra el motivo; si no, Hop no aparece.
- `percentage_rate` vacío o cero: no se aplica porcentaje.

## Qué otras partes se ven afectadas

- [Selección de punto de retiro](seleccion-de-punto-de-retiro.md): comparte el registro del punto en el carrito y depende de la tarifa para mostrar el selector.
- [Conexión con Hop](conexion-con-hop.md): cada cálculo de tarifas hace al menos una llamada a Hop.
- [Despacho de pedidos](despacho-de-pedidos.md): calcula el paquete con reglas parecidas pero en otro código, así que un cambio en las medidas hay que hacerlo en los dos lugares.
- [Direcciones de Perú](direcciones-de-peru.md): define el destino cuando no hay código postal.
- Sistema externo: Hop.

## Decisiones y pendientes

### Decisiones conocidas

- La cotización siempre envía el tipo `E` y no lee `shipping/hop/shipping_type`, y envía `size_category` siempre que esté configurado. Es intencional, según confirmó el desarrollador.
- Un código postal con letras no cotiza, salvo en Perú con ubigeo. Es lo esperado en todos los países, según confirmó el desarrollador.
- El peso se envía anidado en `package` porque Hop ignora el campo plano (comentario en `estimatePrice`).

### Diferencias detectadas

- `proccessAdditionalValidation` está mal escrito, así que no reemplaza al método de Magento `processAdditionalValidation`. El comentario de `isZipCodeRequired` confirma que la validación nativa sí corre.
- `max_package_weight` se lee como decimal, pero `collectRates` lo convierte a entero antes de comparar: un máximo de 1,5 kilos funciona como 1.
- El despacho envía `size_category` solo si falta una medida; la cotización lo envía siempre. Ver la decisión de arriba.

### Sin confirmar

- Si consultar el vendedor en cada cálculo de tarifas afecta los tiempos de la compra en tiendas con mucho tráfico.
