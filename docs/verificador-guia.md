# Verificador de configuración y conexión con Hop

El módulo revisa solo su configuración y la conexión con Hop, y avisa en el panel de
administración de Magento qué falla, en qué vista de tienda y cómo resolverlo. Así un problema
se ve antes de que dejen de entrar ventas por Hop o de que los pedidos queden sin despachar.

Guías relacionadas: [Guía de uso](guia-usuario.md) · [Instalación](instalacion.md) ·
[Envíos multibulto](multibulto-guia-admin.md).

---

## 1. Cómo se ve el aviso

Si hay al menos un problema, arriba de todas las pantallas del panel aparece una franja con la
cantidad de errores y de advertencias y el link **Ver qué falla y cómo resolverlo**.

| Color | Qué significa |
|---|---|
| **Rojo** | Hay al menos un **error**: algo que hoy hace que Hop no aparezca en el checkout, que los pedidos no se despachen o que se despachen con datos equivocados. |
| **Amarillo** | Solo hay **advertencias**: algo para revisar que puede estar bien según el caso. |

Ninguna de las dos bloquea nada: el panel, el checkout y los despachos siguen funcionando como
siempre. Sin problemas, no se ve ninguna franja.

La franja la ven solo los usuarios con permiso para la configuración de envíos de Magento
(*Tiendas → Configuración → Ventas → Métodos de envío*), que son quienes pueden corregirla.

El link lleva al **detalle** (*Envíos Hop → Verificador*, ruta `hop/health/index`), una tabla
con, para cada problema:

- si es error o advertencia,
- qué falla,
- en qué vista de tienda (si son varias, van juntas),
- desde cuándo,
- cómo resolverlo.

## 2. Qué se revisa

| # | Revisión | Cuándo es problema | Tipo |
|---|---|---|---|
| 1a | Credenciales | Hop rechaza el inicio de sesión | Error |
| 1b | Vendedor | Hop informa que el vendedor no está activo, o no lo encuentra | Error |
| 1c | Conexión | Hop no responde | Advertencia: *No se pudo verificar la conexión con Hop* |
| 2 | Datos para despachar | Está vacío el código de vendedor, el código de almacenamiento, el tipo de envío o los días de preparación (0 es válido) | Error |
| 3 | Documento del comprador | Si se usa el taxvat: el campo no se muestra en la tienda. Si se usa un atributo propio: está vacío o no existe como columna de los pedidos | Error |
| 4 | Modo de Hop contra tienda demo | La tienda no está marcada como demo y Hop está en modo prueba, o está marcada como demo y Hop está en modo productivo | Advertencia |
| 5 | Cotización de prueba | Hop no cotiza desde el código postal de origen hasta un punto cercano | Error. Sin puntos cerca del origen: advertencia *No se pudo probar la cotización* |
| 6 | Tarea programada | La tarea de despacho de Hop no termina bien hace más de una hora, o nunca terminó | Error |
| 7 | Avisos de estado | Están activados y no hay ninguna integración activa con el permiso de Hop | Error |
| 8 | Unidad de peso | La tienda usa libras | Error |
| 9 | Medidas | No hay tamaño de categoría y hay productos simples habilitados sin alto, ancho o largo | Advertencia, con la cantidad de productos |

Solo se revisan las vistas de tienda con Hop **habilitado**. Cuando se vuelve a habilitar Hop
en una vista, sus problemas aparecen enseguida, porque guardar la configuración dispara la
revisión.

Las vistas de tienda de Perú no hacen la cotización de prueba (5): las revisiones de Perú van
en una etapa siguiente.

## 3. Cuándo se actualiza

Un problema corregido desaparece solo, sin que nadie tenga que marcarlo.

| Revisiones | Cuándo se actualizan |
|---|---|
| 2, 3, 4 y 8 (solo leen configuración) | Al recargar la pantalla. |
| 6 y 7 (consultan la base) | Al recargar la pantalla, con hasta 5 minutos de demora. |
| 1, 5 y 9 (consultan a Hop o al catálogo) | Al guardar *Configuración de webservices HOP* (aunque no cambie nada), al guardar *Métodos de envío* si cambió algo de Hop (las vistas de tienda alcanzadas por ese guardado), y cada 6 horas (todas). |

Las credenciales (1a) también se actualizan con las llamadas normales a Hop: si Hop rechaza las
credenciales en el checkout, en un despacho o en la actualización de puntos, el aviso aparece en
la próxima pantalla del panel; y si después una llamada funciona, desaparece solo.

La revisión de credenciales inicia sesión de nuevo en Hop, sin usar el token guardado: un token
que todavía no venció no prueba que las credenciales sigan funcionando.

## 4. Cómo resolver cada problema

| Problema | Qué hacer |
|---|---|
| **Credenciales** | Revisá Client ID, Client Secret, email y contraseña en *Tiendas → Configuración → Ventas → Métodos de envío → Configuración de webservices HOP*, y que el **Modo productivo** corresponda a esas credenciales (las de prueba solo funcionan con el modo productivo en **No**). |
| **Vendedor** | Pedile a Hop que active el vendedor, o revisá que el **código de vendedor** sea el que te dio Hop. Mientras el vendedor no esté activo, Hop no aparece en el checkout. |
| **Conexión** | Puede ser una caída momentánea de Hop o un problema de red del servidor. Si sigue en la revisión siguiente, revisá que el servidor pueda salir a `api.hopenvios.com.ar`. |
| **Datos para despachar** | Completá código de vendedor, código de almacenamiento, tipo de envío y días de preparación en la configuración de webservices HOP. |
| **Documento del comprador** | Ver [Número de documento del cliente](guia-usuario.md#21-número-de-documento-del-cliente). Con taxvat, mostralo en la tienda desde *Clientes → Configuración de clientes → Crear cuenta nueva → Mostrar número de IVA en el frontend*. |
| **Modo de Hop y tienda demo** | Si la tienda vende de verdad, pasá Hop a modo productivo con las credenciales productivas. Si es de prueba, marcala como demo (*Contenido → Diseño → Configuración → HTML Head → Mostrar aviso de demo*) o pasá Hop a modo prueba. |
| **Cotización de prueba** | Revisá el código postal de origen, el código de vendedor, el tamaño de categoría y el país predeterminado de la tienda (*General → Opciones de país*; un Magento recién instalado trae Estados Unidos). Si todo está bien, consultá a Hop con el mensaje del detalle. |
| **Tarea programada** | Revisá que el cron de Magento esté corriendo (`bin/magento cron:run` cada minuto). Sin él, los pedidos no se despachan solos. |
| **Avisos de estado** | Creá o activá una integración con el permiso *Hop Envios → Webhook Notifications* (ver [Estados del pedido según Hop](guia-usuario.md#6-estados-del-pedido-según-hop)), o desactivá los avisos si no los usás. |
| **Unidad de peso** | Cambiá la unidad de peso a kilos (*General → Opciones de configuración regional*) y revisá el peso de los productos. Hop toma el peso como kilos sin convertir. |
| **Medidas** | Elegí un **Tamaño de categoría** o completá alto, ancho y largo en los productos (ver [Atributos de medidas](guia-usuario.md#24-atributos-de-medidas-alto-ancho-y-largo)). |

## 5. Pedidos que fallaron por credenciales

Cuando Hop rechaza las credenciales al despachar, el pedido queda con un motivo propio. El
bloque **Información Hop Envíos** del pedido muestra *Error al despachar: Hop rechazó las
credenciales* y qué revisar.

Esos pedidos **se reintentan solos** cuando las credenciales vuelven a funcionar (la primera
llamada a Hop que funcione, o la revisión al guardar la configuración), en la siguiente corrida
de la tarea de despacho, que corre cada minuto. Cada pedido reintentado queda con un comentario
en su historial: *Reintentado automáticamente al recuperarse las credenciales de Hop*, con el
resultado.

No se reintentan:

- los pedidos cancelados, cerrados o retenidos,
- los de hace más de 30 días,
- los que ya se despacharon mientras tanto (por ejemplo, con **Enviar a HOP**),
- los que fallaron por otro motivo (esos siguen como hasta ahora).

Los pedidos completos sí se reintentan: Magento pasa a completo un pedido facturado en cuanto se
crea su envío, justo antes del despacho a Hop.

Para los fallos de antes de esta versión (los que se reconocen por el texto "Authorization fail"),
además se excluyen los completos: un pedido de hace semanas pudo haberse resuelto por fuera de Hop
(otro transportista o retiro en el local) sin cancelarse en Magento, y si se reintentara, Hop
crearía un envío real. Si alguno de esos igual tiene que salir por Hop, usá **Enviar a HOP**.

Si el envío ya se había creado a mano en el panel de Hop, Hop avisa que la referencia del pedido
ya está en uso y no se crea otro. En un pedido de un solo bulto el módulo intenta recuperar ese
envío (lo busca en Hop y compara el mail del comprador y el precio). Si no puede, o si es un
multibulto, el pedido queda marcado *Ya existe un envío en Hop con esta referencia*, sin el
botón **Enviar a HOP**: buscalo en el panel de Hop. Si Hop no responde al buscar ese envío, no se
marca así: se vuelve a intentar más tarde, como cualquier falta de respuesta de Hop.

Mientras las credenciales estén rechazadas, Hop no aparece en el checkout, como siempre. Apenas
vuelven a funcionar, aparece en la primera cotización, sin tener que guardar nada en Magento.

---

## Para el equipo técnico

- **Código.** `Model/Health/` (revisiones, estado de credenciales y resultados),
  `Block/Adminhtml/Health/` (franja y detalle), `Cron/HealthCheck.php` (cada 6 horas) y
  `Observer/HealthConfigSaved.php` (guardado de las secciones `carriers` y `shipping`).
- **La franja es un bloque propio** en el contenedor `notifications` del panel y no un mensaje
  de sistema de Magento (`MessageInterface`): en Magento 2.4.8 esos solo muestran texto con
  severidad crítica, escapan el link al detalle y abren una ventana emergente.
- **Costo por pantalla.** La franja lee configuración, hace una consulta a
  `hop_envios_health_check` y dos consultas cacheadas 5 minutos (`cron_schedule` y las
  integraciones). Nunca llama a Hop ni recorre el catálogo, y solo escribe cuando un problema
  aparece, cambia de tipo o se resuelve (no cuando cambia el texto: se traduce al idioma de cada
  administrador).
- **Cuentas.** Las vistas de tienda se agrupan por cuenta (mismas credenciales y mismo modo).
  La clave de la cuenta es un HMAC con la clave de la instalación: no se guardan credenciales.
- **Credenciales en las llamadas normales.** `Webservice::login()` distingue rechazo (un 401, o
  cualquier otra respuesta con los `errors` de Hop salvo 429 y 5xx, por ejemplo una cuenta
  bloqueada) de falta de respuesta (excepción, HTTP 5xx, 429, un 403 o 404 de un intermediario
  sin el formato de errores de Hop, o una respuesta que no es JSON). Con un rechazo, el resto del
  proceso (una carga de página o una corrida de una tarea programada) no vuelve a llamar a Hop:
  una cotización hace como máximo una llamada. El corte se reinicia al cambiar de vista de tienda
  y en la revisión. Si el token venció y Hop no responde al inicio de sesión, es falta de
  respuesta. El estado se guarda en la caché de Magento y en la tabla, y solo se escribe cuando
  cambia.
- **Motivo de despacho.** `DispatchError::CODE_CREDENTIALS` lo asigna quien despacha cuando
  `Webservice::wasRejectedByCredentials()`: ese despacho llegó a llamar a Hop y Hop rechazó el
  inicio de sesión. No alcanza con `isCredentialsRejected()`, que vale para todo el proceso: un
  despacho que falla antes de llamar a Hop (por ejemplo, sin punto de retiro) no es de
  credenciales. Un 401 de una llamada con el inicio de sesión aceptado (por ejemplo, un vendedor
  que no corresponde al cliente) tampoco: queda para revisión manual, como cualquier 4XX. No
  cuenta para los escalones de reintento por errores del servidor (1, 10 y 30 minutos).
- **Reintento al recuperarse.** `Model/Health/CredentialRetryScheduler.php` corre al principio de
  cada corrida de la tarea de despacho, fuera de cualquier transacción: busca las vistas de tienda
  con despachos que esperan credenciales y, si la cuenta de esa vista ya funciona (último inicio
  de sesión real aceptado), los reprograma para ya. No corre dentro del inicio de sesión, que
  puede pasar en el checkout o en la transacción de guardado de un envío. No depende de que la
  cuenta "cambie" de estado: si alguien carga una contraseña mal y vuelve a la correcta, la cuenta
  correcta ya figuraba como buena. Al reintentar se quita el motivo recién cuando se va a intentar
  el despacho; un pedido que ya no se puede despachar queda para revisión manual. Los fallos
  anteriores a esta versión, que no guardaban el motivo, se reconocen una sola vez por el texto
  `Authorization fail` (parche `ClassifyCredentialFailures`). Qué pedidos se reintentan lo decide
  un solo criterio, `Model/CredentialRetryCriteria.php`: fallidos, ni cancelados, cerrados ni
  retenidos, y creados en los últimos 30 días; para el parche de pedidos viejos, tampoco
  completos.
- **Guardado de la configuración.** Guardar la sección `shipping` (donde están las credenciales
  de Hop) corre las revisiones siempre, aunque no cambie nada: es lo que hace alguien después de
  que Hop le desbloquea la cuenta. Guardar la sección `carriers`, que tiene todos los
  transportistas, solo las corre si cambió alguna ruta `carriers/hop/*`. Cada llamada a Hop de
  la revisión espera como máximo 10 segundos, si el inicio de sesión no responde no se hacen las
  demás, y la revisión al guardar tiene un tope de 25 segundos en total: lo que no alcance queda
  con su resultado anterior hasta la revisión de 6 horas.
- **Referencia en uso.** `DispatchError::CODE_REFERENCE_IN_USE` ya no se puede reintentar:
  Hop rechaza cualquier otro envío con la misma referencia.
