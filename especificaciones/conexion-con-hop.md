# Conexión con Hop

Configuración que lo controla: `shipping/hop/client_id`, `shipping/hop/client_secret`, `shipping/hop/email`, `shipping/hop/password`, `shipping/hop/modo_productivo`, `shipping/hop/pickup_points_api_version`.

## Para qué sirve

Concentra toda la comunicación con Hop: autenticación, elección del ambiente y llamadas. Los demás componentes piden datos a esta clase y no hablan con Hop directamente, salvo para descargar los archivos de etiquetas.

## Dónde vive

- Cliente: `Model/Webservice.php` (`setStoreId`, `ensureInitialized`, `curl`, `login`, `getLastToken`, `saveNewToken`, `getLastStatus`).
- Tokens (credenciales temporales de acceso): `Model/Token.php`, tabla `hop_tokens`.
- Registro de actividad: `Logger/Logger.php`, `Logger/LoggerInterface.php`, `Logger/Handler/MessageHandler.php`, `Logger/Handler/ErrorHandler.php`, `Helper/Data.php` (`log`).
- Lectura de configuración: `Helper/Data.php`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Rutas de Hop que usa el módulo:

| Ruta | Para qué |
| --- | --- |
| `v1/login` | Pide el token |
| `v1/sellers/{código}` | Verifica que el vendedor esté activo |
| `v1/pickup_points` o `v3/pickup_points` | Puntos por código postal o por coordenadas |
| `v1/postal_codes` | Códigos postales de un país, con coordenadas |
| `v1/pricing/estimate` | Precio del envío |
| `v1/shipping` | Crea el envío, simple o multibulto |
| `v1/search_shipments` | Busca un envío por referencia |

Escribe `hop_tokens` y los logs `var/log/hop<mes>_<año>.log` y `var/log/error_hop_<mes>_<año>.log`.

## Comportamiento

1. `setStoreId` fija la tienda. Si cambia, descarta las credenciales y el token en memoria.
2. Antes de cada llamada, `ensureInitialized` lee las credenciales de esa tienda y hace `login` una sola vez por tienda.
3. `login` busca el último token del cliente. Si no venció, lo reutiliza.
4. Si venció, o se fuerza uno nuevo, lo pide a Hop y lo guarda. La expiración se divide por 1000 antes de guardarla.
5. El ambiente sale de `modo_productivo`: si está apagado, la dirección lleva el prefijo `sandbox-`.
6. `curl` envía el token como cabecera. Si Hop responde 401 (no autorizado), pide un token nuevo y repite la llamada una sola vez.
7. El código de la última respuesta queda en `getLastStatus`, y lo usa [Reintentos de despacho](reintentos-de-despacho.md).

La versión de la consulta de puntos solo acepta `v1` o `v3`. Cualquier otro valor usa `v1`.

## Errores y casos límite

- Excepción de red: el estado queda en 0, se registra, se muestra un mensaje en el panel y `curl` devuelve `false`.
- Hop responde errores en el login: se registra y `login` devuelve `false`. No queda inicializado, así que la llamada siguiente vuelve a intentarlo.
- Respuesta de login sin errores y sin token: `login` devuelve `true` igual. Guardar el token falla, se registra, y la llamada sigue sin token hasta recibir un 401.
- Se recibe 401 otra vez después de renovar: la respuesta se devuelve como cualquier otra.

## Qué otras partes se ven afectadas

Todos los componentes que hablan con Hop dependen de este: [Cotización de envío](cotizacion-de-envio.md), [Sincronización de puntos](sincronizacion-de-puntos.md), [Despacho de pedidos](despacho-de-pedidos.md) y [Reintentos de despacho](reintentos-de-despacho.md). Un cambio en el ambiente o en la autenticación corta la venta y el despacho de todas las tiendas. Es un área sensible.

Sistema externo: Hop.

## Decisiones y pendientes

### Decisiones conocidas

- La expiración se divide por 1000 porque, según el desarrollador, Hop la devuelve en milisegundos. Se da por hecho; no se verificó contra la documentación de Hop.
- `setStoreId` existe para que cada tienda use sus propias credenciales (comentario de la clase).

### Diferencias detectadas

- `login` envía las credenciales en la cadena de consulta de la dirección, no en el cuerpo del pedido.
- `client_secret` y `password` no tienen modelo de cifrado en `etc/adminhtml/system.xml`: se guardan como texto en la configuración.
- `hop_tokens` no se purga: cada login agrega una fila.
- El mensaje de error de red del login dice "al solicitar cotización", aunque es un login.
- Se dijo que todo el diagnóstico está en los logs `hop*`. `Cron/GenarateShipment.php`, `Cron/GenerateShippingLabels.php`, `Model/Carrier/Hop.php` y `Controller/Adminhtml/Label/Download.php` usan el registro general de Magento.

### Sin confirmar

- Si un token de pruebas podría reutilizarse en producción con el mismo cliente, porque `hop_tokens` no guarda el ambiente.
