# Envíos multibulto con Hop — Guía para el administrador

Guía de uso del módulo **Hop Envíos** para quien opera la tienda desde el panel de
administración de Magento. Explica qué es un envío multibulto, todas las formas de
despachar un pedido a Hop, qué ves en cada caso y qué hacer cuando algo no sale como
esperabas.

No hace falta conocimiento técnico para seguirla. Al final hay un anexo con la
configuración y los puntos que conviene escalar al equipo técnico.

---

## 1. La idea en una frase

> **Cada "Envío" que creás en Magento se convierte en un bulto del pedido en Hop.**

Un pedido con un solo envío viaja como **un bulto**. Un pedido que dividís en tres
envíos viaja como **tres bultos**, cada uno con su propio número de seguimiento y su
propia etiqueta, todos hacia el mismo punto de retiro.

### La regla que hay que tener presente

Hop recibe la lista de bultos **una sola vez**, en el momento en que el pedido se
despacha. No existe forma de agregar un bulto a un pedido que ya fue despachado.

De ahí se derivan las dos consecuencias prácticas más importantes:

1. **El aviso a Hop sale con el último envío.** Mientras queden artículos sin enviar, los
   envíos parciales que ya creaste **no tienen número de seguimiento**. Esto es correcto,
   no es un error: Hop todavía no fue notificado.
2. **Si vas a dividir el pedido, decidí la división antes de despachar.** Un envío que
   crees después queda sin seguimiento y sin etiqueta de Hop, y no hay manera de
   recuperarlo desde el panel (ver [sección 7](#7-límites-y-cosas-que-no-se-pueden-hacer)).

---

## 2. Las cinco formas de despachar un pedido a Hop

Todas conviven en la misma tienda. La diferencia está en **quién dispara el despacho** y
en **cuántos bultos** termina teniendo el pedido.

| # | Forma | ¿Quién la dispara? | Bultos | ¿Crea "Envío" en Magento? |
|---|-------|--------------------|--------|---------------------------|
| 1 | Botón **Enviar** con todos los artículos | Vos, a mano | 1 | Sí, uno |
| 2 | Botón **Enviar** varias veces, con artículos parciales | Vos, a mano | **N (multibulto)** | Sí, uno por bulto |
| 3 | Botón **Enviar a HOP** | Vos, a mano | 1 | **No** |
| 4 | Cambio de estado del pedido | Automático | 1 | **No** |
| 5 | Tarea programada de despacho | Automático | 1 | Sí, uno |

**Solo la forma 2 genera multibulto.** Las otras cuatro siempre producen un único bulto.

### Forma 1 — Un envío con todo (lo más común)

1. `Ventas → Pedidos`, abrí el pedido.
2. Botón **Enviar** (o *Ship*) arriba a la derecha.
3. Dejá todos los artículos con su cantidad completa y confirmá con **Enviar**.

Al guardarse el envío, el módulo avisa a Hop, guarda el número de seguimiento en el
envío y empieza a generar la etiqueta.

### Forma 2 — Varios envíos parciales (multibulto)

Es el caso que da nombre a esta guía. Sirve cuando el pedido se despacha en varias cajas
o en varias tandas.

1. Abrí el pedido y hacé clic en **Enviar**.
2. **Bajá las cantidades** para enviar solo una parte (por ejemplo, un artículo en 1 y el
   otro en 0). Confirmá.
3. Repetí el paso anterior tantas veces como bultos necesites.
4. En el **último** envío, dejá los artículos restantes con su cantidad completa.

Qué vas viendo en el camino:

- Mientras queden artículos pendientes, el botón **Enviar** sigue visible y los envíos
  creados **no muestran número de seguimiento**.
- Al confirmar el último envío, el botón **Enviar** desaparece y, en ese mismo momento,
  **todos** los envíos del pedido reciben su número de seguimiento — uno distinto por
  bulto.
- Un minuto después, aproximadamente, cada envío tiene su etiqueta lista.

### Forma 3 — Botón "Enviar a HOP"

Aparece en la barra superior del pedido cuando el pedido tiene un **punto de retiro Hop**
seleccionado y todavía no fue despachado. Despacha el pedido a Hop **sin crear ningún
envío en Magento**: la pestaña *Envíos* del pedido queda vacía.

Sirve cuando querés generar la etiqueta de Hop sin tocar el inventario ni notificar al
cliente con el email de envío de Magento.

Después de usarlo:

- El mensaje en pantalla refleja lo que respondió Hop: *"Orden enviada a Hop
  correctamente"* o *"Error al enviar la orden a Hop"*.
- El botón **Enviar a HOP** desaparece y en su lugar aparecen **Descargar etiqueta HOP** y
  **Estado HOP**.

> **Importante:** este camino produce siempre **un solo bulto**. Si el pedido ya se
> despachó por acá, ya no podés convertirlo en multibulto.

### Forma 4 — Automático al cambiar el estado del pedido

Si en la configuración del módulo hay estados cargados en **Estados para generación de
etiqueta**, cada vez que un pedido Hop entra en uno de esos estados el módulo lo despacha
solo a Hop, con el mismo efecto que el botón **Enviar a HOP**: un bulto, sin envío de
Magento.

> **Ojo con esto si trabajás con multibulto.** Si el estado que usás habitualmente
> (por ejemplo *Processing*) está en esa lista, los pedidos se despachan en cuanto llegan
> a ese estado y ya no podés dividirlos en bultos. Consultá con el equipo técnico qué
> estados están configurados; ver [anexo](#anexo-a-configuración-que-afecta-al-multibulto).

### Forma 5 — Tarea programada de despacho

Hay una tarea automática que corre cada minuto y crea el envío de Magento de los pedidos
Hop que quedaron pendientes de despacho, con todos los artículos en un único envío.

Tiene un resguardo importante para no pisarte el trabajo: **si el pedido ya tiene al
menos un envío creado, la tarea no interviene**. Es decir, si empezaste a dividir un
pedido a mano, nadie va a crear un envío en competencia mientras terminás.

---

## 3. Dónde ver los datos de cada bulto

### En el envío individual

`Pedido → pestaña Envíos → abrir un envío`:

- **Información de seguimiento**: el número de seguimiento de ese bulto.
  Debe haber **una sola fila**. Si ves dos filas con el mismo número, reportalo.
- Botón de etiqueta:
  - **Crear etiqueta de envío** → la etiqueta todavía no está lista.
  - **Imprimir etiqueta de envío** → la etiqueta ya está guardada; descarga el PDF.

### En la barra superior del pedido

Lo que ves depende de cuántos bultos tenga el pedido:

| Situación | Botones en el pedido |
|-----------|----------------------|
| Sin despachar | **Cambiar punto Hop**, y **Enviar a HOP** si hay punto de retiro |
| Despachado, 1 bulto | **Descargar etiqueta HOP**, **Estado HOP** |
| Despachado, 2 o más bultos | **Etiquetas HOP** |

**Etiquetas HOP** abre una ventana con **una fila por bulto**, y en cada fila:

- el número del envío de Magento,
- el número de seguimiento del bulto,
- un link **Descargar etiqueta** (el PDF de ese bulto),
- un link **Estado** (la página pública de seguimiento de Hop para ese número).

Solo aparecen en el listado los bultos que efectivamente tienen etiqueta en Hop. Si
esperabas tres filas y ves dos, uno de los bultos falló: mirá la
[sección 6](#6-cuando-algo-sale-mal).

### Datos del punto de retiro

En la ficha del pedido, bajo **Información Hop Envíos**, se ve el punto seleccionado
actualmente, el que había elegido el cliente en el checkout y la descripción original del
envío.

---

## 4. Las etiquetas y el minuto de espera

Hop devuelve la dirección de la etiqueta apenas confirma el despacho, pero el archivo
tarda en estar realmente disponible para descargar — **hasta un minuto**.

Cómo lo maneja el módulo:

- Intenta generar la etiqueta al instante. Si el archivo todavía no está, **no guarda
  nada** y vuelve a intentarlo en segundo plano.
- Hay una tarea que reintenta **cada minuto**, por su cuenta, hasta que la etiqueta queda
  guardada. **No hace falta clickear nada.**
- Los reintentos se hacen durante la **primera hora** desde el despacho. Si pasada esa
  hora la etiqueta nunca se generó, ya no se reintenta más y hay que escalarlo.

Qué significa para tu rutina:

1. Despachás el pedido.
2. Si entrás enseguida al envío, es normal que el botón diga **Crear etiqueta de envío** y
   que, al usarlo, te dé un error de descarga. No es una falla: la etiqueta todavía no
   está publicada.
3. Esperá uno o dos minutos y refrescá. El botón pasa a **Imprimir etiqueta de envío**.
4. En multibulto, cada bulto recorre este ciclo por separado, en paralelo.

> **Señal de alarma:** si descargás un PDF de menos de 1 KB que no abre en ningún lector,
> reportalo. Es un síntoma conocido y no deberías volver a verlo.

### Formatos de etiqueta

El formato lo define la configuración del módulo, no el pedido:

- **JPEG** (y otros formatos de imagen): el módulo lo convierte a **PDF** y te lo entrega
  como archivo descargable.
- **ZPL**: es un archivo para impresora de etiquetas. Se abre directo, sin pasar por la
  conversión a PDF.

### Permisos

Para descargar etiquetas de Hop el usuario admin necesita el permiso correspondiente en su
rol. Si un operador ve el botón pero recibe *"No está autorizado a acceder a este
recurso"*, hay que habilitárselo en `Sistema → Permisos → Roles de usuario`.

---

## 5. Seguimiento y estados del pedido

- **El cliente** recibe el email de envío de Magento con el número de seguimiento, y puede
  consultarlo en su cuenta. En multibulto recibe un email por cada envío que confirmás.
- **Vos** podés consultar el estado de cualquier bulto con el link **Estado** (en el
  listado de *Etiquetas HOP*) o **Estado HOP** (pedidos de un solo bulto).
- **Hop avisa a Magento** los cambios de estado del envío, y el módulo mueve el estado del
  pedido según la tabla de equivalencias configurada (*Manejo de estado de las ventas*).
  Cada novedad queda como comentario en el historial del pedido, indicando el número de
  seguimiento del bulto al que corresponde.

> **En multibulto, el estado es del pedido, no del bulto.** Todos los bultos comparten la
> misma referencia de pedido en Hop, así que la novedad de cualquiera de ellos mueve el
> estado del pedido completo. El detalle de qué bulto la generó está en el comentario del
> historial. Si necesitás saber en qué anda cada caja por separado, usá el link **Estado**
> de cada bulto.

---

## 6. Cuando algo sale mal

### Un envío parcial no tiene número de seguimiento

**Es lo esperado** mientras queden artículos sin enviar. El aviso a Hop sale con el último
envío del pedido y en ese momento se completan todos los bultos a la vez.

Si ya creaste el último envío (el botón **Enviar** desapareció) y algún envío sigue sin
número: refrescá la página. Si después de un rato sigue vacío, es una falla real —
escalalo.

### Dos bultos con el mismo número de seguimiento

No debería pasar: cada bulto tiene su propio número. Si ves el mismo número repetido en
dos envíos, reportalo al equipo técnico.

### Un envío tiene dos filas de seguimiento

También es una falla. Debe haber exactamente una fila por envío.

### Apareció un mensaje "Error en bulto N de Hop: ..."

Hop rechazó **ese** bulto en particular (por ejemplo, por medidas o peso inválidos). Los
demás bultos del pedido sí se crearon.

Ese bulto queda **sin número de seguimiento y sin etiqueta**, y no aparece en el listado de
*Etiquetas HOP*. No se puede reintentar desde el panel: el pedido ya está despachado en Hop
y no admite bultos nuevos. Hay que resolverlo con Hop por fuera de Magento, o cargar ese
bulto como un envío aparte.

### Apareció un error general "Error en respuesta multibulto de Hop"

Hop rechazó **toda** la solicitud (credenciales, datos del cliente o del envío inválidos).
Ningún bulto se creó. El pedido queda sin despachar y podés volver a intentarlo: creá el
último envío otra vez una vez corregido el problema, o pedile al equipo técnico que revise
el log del módulo para ver el motivo exacto.

### Creé un envío después de despachar y quedó sin etiqueta

Es el límite de Hop descrito en la [sección 1](#la-regla-que-hay-que-tener-presente). Ese
envío no se puede representar en Hop y queda marcado internamente como no soportado. No hay
solución desde el panel.

### El botón "Enviar a HOP" no aparece

Solo aparece si se cumplen las tres condiciones: el pedido usa el método de envío Hop, hay
un **punto de retiro** asociado al pedido, y el pedido **todavía no fue despachado**. Si el
pedido ya está despachado, en su lugar verás los botones de etiqueta y estado.

### El pedido ya está despachado en Hop pero no veo los botones de etiqueta

Puede tratarse de un pedido antiguo, despachado antes de que el módulo llevara este
registro. La actualización del módulo incluye un ajuste que recupera esos casos; si aun así
falta, escalalo.

---

## 7. Límites y cosas que no se pueden hacer

| Límite | Detalle |
|--------|---------|
| **No se agregan bultos después del despacho** | Una vez que el pedido se despachó a Hop, la lista de bultos queda cerrada. |
| **Un solo punto de retiro por pedido** | Todos los bultos van al mismo punto. No se puede repartir un pedido entre dos puntos. |
| **No se elige qué va en cada bulto más allá del envío** | El contenido de cada bulto es exactamente el de su envío de Magento. |
| **No se recotiza al dividir** | El costo de envío es el que se cotizó en el checkout; partir el pedido en bultos no lo recalcula ni le cobra más al cliente. |
| **Cancelar un envío en Magento no cancela el bulto en Hop** | Hay que gestionar la cancelación directamente con Hop. |
| **No hay reintento manual del despacho** | Si Hop rechaza un bulto, el panel no ofrece un botón para reintentarlo. |
| **Las medidas se calculan, no se cargan a mano** | No hay una pantalla para escribir alto/ancho/largo de cada bulto; salen de los atributos de los productos (ver anexo). |

---

## 8. Recomendaciones de operación

1. **Decidí la división antes de empezar.** Contá las cajas primero y creá un envío por
   caja. Una vez que confirmás el último, no hay vuelta atrás.
2. **Si operás multibulto, revisá los estados automáticos.** Pedile al equipo técnico que
   confirme qué estados están en *Estados para generación de etiqueta*: si el estado
   habitual de tus pedidos está ahí, se despachan solos con un bulto y perdés la
   posibilidad de dividirlos.
3. **No pelees con el minuto de espera.** Despachá y seguí con otra cosa; volvé en un par
   de minutos y la etiqueta va a estar. Clickear repetidamente no la apura.
4. **Cargá bien alto, ancho, largo y peso de los productos.** Son los datos con los que se
   arma cada bulto. Un producto sin medidas hace que el bulto viaje con el tamaño genérico
   configurado por defecto, que puede no corresponder.
5. **Antes de reportar una falla, refrescá y esperá.** Muchos síntomas (seguimiento vacío,
   etiqueta no lista) se resuelven solos en el minuto siguiente.

---

## Anexo A — Configuración que afecta al multibulto

`Tiendas → Configuración → Ventas → Métodos de envío → Configuración de webservices HOP`

| Opción | Qué hace en multibulto |
|--------|------------------------|
| **Tipo de Etiqueta** | Formato de todas las etiquetas del pedido (JPEG se convierte a PDF; ZPL se descarga tal cual). |
| **Tamaño de Etiqueta** | Tamaño de la etiqueta cuando el formato no es ZPL. |
| **Tamaño de categoría** | Tamaño genérico que se usa **en cada bulto** al que le falte alguna medida (alto, ancho o largo). |
| **Atributo para Alto / Largo / Ancho** | Atributos de producto de donde salen las medidas. Por bulto: el **alto se suma** entre sus artículos, y **ancho y largo toman el mayor** de ellos. El peso se suma. |
| **Tipo de Envío** | Modalidad (retiro en punto, entrega, o ambas). Igual para todos los bultos. |
| **Días de preparación** | Días de demora informados a Hop. Igual para todos los bultos. |
| **Estados para generación de etiqueta** | Estados de pedido que disparan el despacho automático de un bulto. **Clave para multibulto:** ver recomendación 2. |
| **Validar cliente** / **documento del cliente** | Datos del destinatario enviados a Hop. |
| **Manejo de estado de las ventas** | Equivalencia entre los estados de Hop y los de Magento para los avisos automáticos. |

---

## Anexo B — Qué pedirle al equipo técnico

Cuando reportes un problema, estos datos aceleran el diagnóstico:

- Número de pedido y número de los envíos involucrados.
- Por qué camino se despachó (¿botón **Enviar**? ¿**Enviar a HOP**? ¿automático?).
- Cuántos bultos esperabas y cuántos aparecieron.
- Los números de seguimiento que ves, si hay.
- Hora aproximada del despacho.
- El mensaje de error completo, si hubo alguno en pantalla.

Y estas son cosas que solo el equipo técnico puede verificar:

- El **log del módulo**, donde queda el detalle de cada llamada a Hop y su respuesta.
- Que las **tareas programadas de Magento estén corriendo** — sin eso, las etiquetas no se
  generan solas ni funciona el despacho automático.
- Que el módulo esté **actualizado** en el entorno (`setup:upgrade` ejecutado).
- Para tiendas de **Perú**: conviene verificar con Hop que el ubigeo llegue correctamente
  en los pedidos multibulto, ya que el dato se envía de forma distinta que en los pedidos
  de un solo bulto.
