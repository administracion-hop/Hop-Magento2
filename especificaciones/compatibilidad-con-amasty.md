# Compatibilidad con Amasty

Configuración que lo controla: `amasty_checkout/general/enabled`.

## Para qué sirve

Hace que el selector de puntos funcione cuando la tienda usa Amasty One Step Checkout en lugar del proceso de compra nativo. Sin esta adaptación, la plantilla de Amasty no mostraría el botón para elegir un punto.

## Dónde vive

- Detección: `Helper/Data.php` (`isAmastyOscEnabled`).
- Inyección: `Plugin/Framework/View/Page/Config/Renderer/InjectAmastyFlag.php`, declarado en `etc/frontend/di.xml`.
- Configuración de la pantalla: `view/frontend/web/js/hopAmastyCheckoutEnabled.js`, `view/frontend/web/js/hopAmastyCheckoutDisabled.js`.
- Adaptaciones: `view/frontend/web/js/model/one-step-layout-mixin.js`, `view/frontend/web/js/model/amasty-shipping-rate-service-mixin.js`, `view/frontend/web/template/onepage/shipping/methods.html`.
- Otros usos: `Model/Carrier/Hop.php` (`collectRates`) y `view/frontend/web/js/view/hop.js` (`selectHopPoint`).

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Lee si el módulo `Amasty_Checkout` o `Amasty_CheckoutCore` está habilitado y la configuración de Amasty.

Agrega uno de los dos archivos de configuración a las páginas de la tienda y deja la marca `window.hop.amasty_checkout_disabled`.

## Comportamiento

1. Antes de armar los recursos de cada página de la tienda, el complemento inserta el archivo de Amasty activo o inactivo, antes de `requirejs/require.js`.
2. Con Amasty activo, el archivo registra las dos adaptaciones y reemplaza la plantilla de métodos de envío por la del módulo, que incluye el selector.
3. Después de elegir un punto, `hop.js` llama a `forceUpdateRates` para que Amasty actualice el precio.
4. En `collectRates`, si no hay destino utilizable y Amasty está activo, el método se agrega igual para que la opción esté visible.

## Errores y casos límite

- Módulo de Amasty habilitado pero apagado en su configuración: se comporta como el proceso de compra nativo.
- Módulo de Amasty ausente: el archivo activo no se carga, y no se referencia ningún componente de Amasty.
- `forceUpdateRates` no disponible: `hop.js` lo salta.

## Qué otras partes se ven afectadas

- [Cotización de envío](cotizacion-de-envio.md): cambia el resultado cuando falta el destino.
- [Selección de punto de retiro](seleccion-de-punto-de-retiro.md): reutiliza su plantilla y su lógica.
- Sistema externo: Amasty One Step Checkout. Un cambio en sus nombres internos puede romper los reemplazos.

## Decisiones y pendientes

### Decisiones conocidas

- La validación de punto obligatorio aplica también con Amasty, según confirmó el desarrollador.

### Diferencias detectadas

- `hopAmastyCheckoutEnabled.js` tiene el comentario "Desactiva mixins/overrides", pero los activa.
- El archivo se agrega en todas las páginas de la tienda, no solo en la de compra.
- `hop.js` deja un mensaje de consola al llamar a `forceUpdateRates`.

### Sin confirmar

- Con qué versiones de Amasty One Step Checkout funciona. El código no lo indica.
