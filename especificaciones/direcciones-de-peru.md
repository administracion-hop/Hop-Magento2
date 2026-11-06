# Direcciones de Perú

Configuración que lo controla: `shipping/hop_peru/ubigeo_source`, `shipping/hop_peru/ubigeo_field`, `shipping/hop_peru/ubigeo_distrito_attribute`, `shipping/hop_peru/ubigeo_provincia_attribute`.

## Para qué sirve

En Perú, Hop identifica el destino con el ubigeo (código oficial de ubicación geográfica), no con un código postal. Este componente obtiene ese código, ya sea de un campo de la dirección o de la combinación de región, provincia y distrito que carga el comprador.

## Dónde vive

- Resolución del código: `Helper/Data.php` (`isUbigeoConfigured`, `getUbigeoSource`, `getUbigeoFromAddress`, `getUbigeoFromDistritoProvinciaValues`).
- Importación: `Model/Ubigeo/PeruUbigeoImporter.php`, `etc/data/peru_ubigeo.csv`, `Controller/Adminhtml/System/Config/ImportPeruUbigeo.php`, `Block/Adminhtml/System/Config/ImportUbigeoButton.php`.
- Atributos de dirección: `Model/Ubigeo/PeruAddressAttributesManager.php`, `Controller/Adminhtml/System/Config/CreatePeruAddressAttributes.php`, `Controller/Adminhtml/System/Config/RevertPeruAddressAttributes.php`, `Controller/Adminhtml/System/Config/ResolvesConfigScopeTrait.php`, `Block/Adminhtml/System/Config/PeruAddressAttributesButton.php`.
- Opciones: `Model/Ubigeo/PeruLocationOptionsProvider.php`, `Model/Ubigeo/Source/PeruProvinciaSource.php`, `Model/Ubigeo/Source/PeruDistritoSource.php`.
- Proceso de compra: `Plugin/Checkout/UbigeoAddressAttributesLayoutProcessorPlugin.php`, `Plugin/Checkout/UbigeoShippingInformationManagementPlugin.php`, `view/frontend/web/js/action/set-ubigeo-shipping-information-mixin.js`, `view/frontend/web/js/mixin/ubigeo-new-address-mixin.js`.
- Paso de la dirección al pedido: `Plugin/Quote/Address/UbigeoCustomAttributeListPlugin.php`, `Plugin/Quote/Address/UbigeoFlattenCustomAttributesPlugin.php`, `Plugin/Quote/Address/UbigeoToOrderAddressPlugin.php`, `etc/extension_attributes.xml`.
- Cuenta del comprador: `Model/ViewModel/Customer/UbigeoAddressAttributes.php`, `view/frontend/layout/customer_address_form.xml`, `view/frontend/templates/address/edit.phtml`, `view/frontend/web/js/customer-address-ubigeo-filter.js`.
- Tablas: `hop_peru_provincia`, `hop_peru_distrito` y las columnas `hop_ubigeo_provincia` y `hop_ubigeo_distrito` de `quote_address` y `sales_order_address`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Hay dos modos, según `ubigeo_source`:

- `field`: el código se lee de un atributo de dirección configurado.
- `mapping`: el código se busca en `hop_peru_distrito` con región, provincia y distrito, sin distinguir mayúsculas.

Devuelve el código, o nada si la dirección no es de Perú o no se puede resolver.

Escribe las tablas de Perú, los atributos de dirección del cliente, la configuración de `hop_peru` y las columnas de dirección.

## Comportamiento

Preparación en el panel:

1. "Importar Ubigeo" carga provincias y distritos desde el archivo CSV (valores separados por comas) dentro de una transacción. Si ya hay datos, no hace nada.
2. "Crear campos" agrega `hop_ubigeo_provincia` y `hop_ubigeo_distrito` a las direcciones y deja la configuración en modo `mapping`, en el mismo alcance de la pantalla.
3. "Revertir" borra esos atributos y limpia la configuración si todavía apunta a ellos.

Proceso de compra en modo `mapping`:

1. Con los atributos del módulo configurados, agrega los selectores de provincia y distrito después de la región, filtrados entre sí.
2. Un cambio en esos campos vuelve a calcular las tarifas, 400 milisegundos después.
3. La búsqueda de puntos envía región, provincia y distrito, porque la dirección todavía no está guardada en el carrito.
4. Al guardar la información de envío, los valores pasan a la dirección del carrito y después a la del pedido.

En la cuenta del comprador, la plantilla de edición de direcciones muestra los mismos selectores.

## Errores y casos límite

- Un departamento del archivo sin región en Magento: la importación se cancela sin escribir nada.
- Error en la importación: deshace los cambios y muestra el mensaje en el panel.
- Dirección incompleta: no se resuelve el código y no hay tarifa, salvo que la dirección tenga código postal numérico.
- Modo `mapping`: el código postal no es obligatorio en Perú (`isZipCodeRequired`).
- `get` con el valor `0` y sin ubigeo resuelto: no hay puntos.

## Qué otras partes se ven afectadas

- [Cotización de envío](cotizacion-de-envio.md) y [Selección de punto de retiro](seleccion-de-punto-de-retiro.md): usan el código como destino.
- [Despacho de pedidos](despacho-de-pedidos.md): el despacho simple lo envía como código postal.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): lo usa para buscar puntos.
- Sistema externo: Hop.

## Decisiones y pendientes

### Decisiones conocidas

- El grupo de Perú no se edita por vista de tienda porque una configuración vieja a ese nivel ganaba sobre la del sitio web sin ningún aviso (comentario en `etc/adminhtml/system.xml`).
- La existencia de los atributos se consulta directo en la base de datos, porque la caché podía informar mal (comentario en `isCreated`).
- La región, la provincia y el distrito viajan en la consulta porque la dirección del carrito se guarda recién al enviar la información de envío (comentario en `get`).

### Diferencias detectadas

- Se dijo que hay regionalización para Uruguay. El código no tiene nada específico de Uruguay: solo se envía el país.
- `ResolvesConfigScopeTrait` contempla la vista de tienda, que en el panel de Perú no se muestra.
- "Revertir" no cambia `ubigeo_source`, que sigue en `mapping`, y no borra las tablas importadas.
- El despacho multibulto no envía el ubigeo. Ver [Despacho de pedidos](despacho-de-pedidos.md).

### Sin confirmar

- Si el modo `field` se usa en alguna tienda.
