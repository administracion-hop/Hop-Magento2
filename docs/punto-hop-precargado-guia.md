# Punto Hop precargado en el checkout — Guía

Explica cómo el módulo **Hop Envíos** le sugiere al cliente el punto de retiro que usó la
última vez, en qué casos lo hace y en cuáles no. Al final hay un anexo técnico.

---

## 1. La idea en una frase

> **Si el cliente ya compró con Hop, el checkout le deja elegido el mismo punto de retiro,
> siempre que siga siendo válido para la dirección de esta compra.**

El cliente lo ve como un punto ya seleccionado: el método Hop muestra la descripción del
punto ("Retirá tu pedido en: …"), el precio se cotiza para ese punto y el botón pasa de
**"Seleccioná un Punto HOP"** a **"Modificar Punto HOP"**. Puede cambiarlo cuando quiera.

---

## 2. Cuándo se precarga

Se tienen que cumplir **todas** estas condiciones:

| Condición | Por qué |
|---|---|
| El cliente está logueado | De un invitado no sabemos cuál fue su última compra |
| Todavía no eligió un punto en esta compra | Lo que elige el cliente siempre manda |
| Tiene al menos un pedido anterior enviado por Hop | Es de donde sale el punto |
| El código postal del punto coincide con el de la dirección de envío | Un punto de otra zona no le sirve, y se cotizaría mal |
| El punto sigue figurando en el listado de puntos de Hop | No sugerir un punto que ya no opera |

## 3. Cuándo **no** se precarga

- **El administrador cambió el punto del último pedido.** Si el punto de retiro se
  modificó desde el admin, la descripción guardada del pedido sigue siendo la del punto
  viejo; para no mostrar un texto que no coincide, no se sugiere nada (no se busca en
  pedidos anteriores).
- **El punto dejó de estar en el listado de Hop.** El listado local se actualiza cada
  hora, así que puede haber un pequeño desfasaje.
- **Pedidos viejos migrados** sin código postal o descripción del punto.
- **Perú con ubigeo por mapeo de Distrito y Provincia.** En ese modo el código postal de
  la dirección no tiene relación con el del punto, así que no hay forma de validar que el
  punto corresponda a la dirección. El cliente elige el punto como siempre. Si el ubigeo
  se lee del campo *Código postal* (modo "Campo de Ubigeo" con el campo `postcode`), la
  precarga funciona igual que en Argentina.

## 4. Preguntas frecuentes

**¿El pedido puede salir con un punto que el cliente no vio?**
No. El punto precargado se muestra en el método de envío y en el botón antes de pagar.

**¿Y si el cliente termina eligiendo otro método de envío?**
El pedido no guarda ningún punto Hop: solo los pedidos enviados por Hop llevan punto de
retiro, aunque el carrito haya tenido uno precargado.

**¿Hay que configurar algo?**
No, no tiene configuración propia. Funciona en todas las tiendas con Hop habilitado.

---

## Anexo técnico

- Lógica: `Model/LastPickupPoint.php` (`getForQuote`). Busca el último pedido `hop_hop`
  del cliente en `OrderPickupPointRepository::getLastByCustomerId` y valida el punto
  contra el caché de puntos (`hop_pickup_points`, refrescado por `Cron/LoadPoints`) con el
  `client_id` del store de la quote.
- Se usa en dos lugares:
  - `Model/Carrier/Hop::collectRates` — si no hay punto en sesión ni en
    `hop_envios_selected_pickup_point`, usa el último punto y, si su CP coincide con el destino, lo
    guarda en la quote y cotiza con él. El descarte por CP distinto ocurre **antes** de
    cotizar, para no cobrar el precio de un punto de otra zona.
  - `Model/PickupPointManagement::getSelectedPoint` (endpoint `selected-point`) — último
    fallback para que `hop.js` lo tome; el JS solo lo acepta si su CP coincide con el de
    la dirección.
- Perú: el carrier saltea la precarga cuando
  `Helper\Data::isPostcodeValidationApplicable($countryId, $storeId)` es `false`, el mismo
  criterio que `isPostcodeValidationApplicable()` en `view/frontend/web/js/view/hop.js`.
  Si se cambia uno, hay que cambiar el otro: si difieren, la quote queda con un punto
  (título y precio) que el checkout no da por seleccionado.
- `Observer/QuoteSubmitSuccessObserver` solo copia el punto de la quote al pedido cuando
  el método es `hop_hop`.
