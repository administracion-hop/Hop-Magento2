# Envíos Hop a Perú — Configuración del Ubigeo

Guía para configurar el módulo **Hop Envíos** en una tienda que vende a Perú. Solo hace
falta si la tienda tiene direcciones de envío en Perú: para el resto de los países nada
de esto cambia el comportamiento.

La instalación y la configuración general están en la [Guía de instalación](instalacion.md)
y la [Guía de uso](guia-usuario.md).

---

## 1. Qué es el Ubigeo y por qué hace falta

Para cotizar, buscar puntos de retiro y generar la guía en Perú, Hop usa el **Ubigeo**:
el código de ubicación peruano, equivalente a nuestro código postal.

Muchas direcciones de Perú no tienen código postal o lo tienen mal cargado, así que el
módulo puede obtener el Ubigeo de dos formas. Esta guía explica cómo configurar cada una.

---

## 2. Dónde se configura

**Tiendas → Configuración → Ventas → Configuración de envío**, sección **Configuración Hop
específica de Perú**.

> **Ojo con el alcance.** Esta sección se configura en **Default** o en **Website**, nunca
> en **Store View**. Si el selector de alcance (arriba a la izquierda) está en un Store
> View, los campos no aparecen: cambiá al Website que vende a Perú. Si tenés varios
> Websites, cada uno que venda a Perú necesita su configuración.

---

## 3. Los dos modos

El campo **Origen del Ubigeo** define cuál se usa.

### Modo 1 — Campo de Ubigeo

El más simple. Sirve cuando la dirección ya tiene un campo con el Ubigeo, o cuando
alcanza con seguir usando el código postal.

- **Campo de Ubigeo vacío** (por defecto): se sigue usando el código postal, como si la
  configuración de Perú no existiera. No rompe nada en tiendas que todavía no migraron.
- **Con un atributo de dirección elegido** (por ejemplo, un campo propio que ya tenga el
  Ubigeo): ese valor reemplaza al código postal en la cotización, la búsqueda de puntos
  de retiro y la generación de la guía.

### Modo 2 — Mapeo de Distrito y Provincia

Para cuando el comprador no carga el Ubigeo directamente, sino que elige **Región** (el
campo de Magento), **Provincia** y **Distrito**. El módulo busca esa combinación exacta
en una tabla interna y de ahí obtiene el Ubigeo.

En este modo:

- El **código postal deja de ser obligatorio** para las direcciones de Perú.
- El checkout muestra dos desplegables nuevos, **Provincia** y **Distrito**. El de
  Distrito se filtra según la Provincia elegida.
- Antes hay que importar la tabla de Ubigeo y crear (o elegir) los campos de Provincia y
  Distrito. Es el paso a paso de abajo.

---

## 4. Configurar el Modo Mapeo, paso a paso (recomendado)

1. Andá a **Tiendas → Configuración → Ventas → Configuración de envío**, con el alcance en
   el Website de Perú.
2. En **Origen del Ubigeo** elegí **Mapeo de Distrito y Provincia**.
3. Tocá **Importar Ubigeo**. Carga, una sola vez, la lista completa de provincias y
   distritos de Perú con su Ubigeo. Si ya estaba importada, avisa y no duplica nada.
4. Tocá **Crear campos de Distrito y Provincia**. Crea dos campos de dirección,
   **Provincia (Perú)** y **Distrito (Perú)**, y los deja elegidos en **Atributo de
   Provincia** y **Atributo de Distrito**.
5. **Guardá** la configuración.

Con eso, el checkout de Perú muestra los desplegables de Provincia y Distrito.

> El paso 3 tiene que ir **antes** del 4: los campos se arman con las opciones de la tabla
> importada.

### Deshacer el paso 4: botón "Revertir"

Una vez creados los campos, al lado del botón aparece **Revertir**, que los borra. Si
después de crearlos alguien eligió a mano otros atributos en *Atributo de Provincia* o
*Atributo de Distrito*, Revertir no toca esa elección: solo limpia la configuración si
todavía apunta a los campos que creó el botón.

---

## 5. Usar atributos de dirección propios

No es obligatorio usar los campos que crea el paso 4. En **Atributo de Provincia** y
**Atributo de Distrito** se puede elegir cualquier atributo de dirección que la tienda ya
tenga. En ese caso:

- Esos atributos tienen que estar **visibles en el formulario del checkout**. Eso se
  configura en Magento, no en el módulo. Si no se ven, el comprador no los puede cargar y
  Hop no cotiza para Perú.
- El valor cargado tiene que coincidir **exactamente** con el nombre oficial de la
  provincia o distrito de la tabla importada. Si no coincide, no se encuentra el Ubigeo.

Por eso se recomienda usar los campos del paso 4: sus opciones salen de la tabla oficial,
así que siempre coinciden.

---

## 6. "Mi cuenta → Direcciones"

Con el **Modo Mapeo y los campos creados por el botón**, el cliente logueado también
puede cargar Provincia y Distrito desde **Mi cuenta → Direcciones**, al crear o editar una
dirección.

Con el Modo Campo, o con el Mapeo usando atributos propios, esos campos no aparecen en
*Mi cuenta*. El cliente los carga en el checkout.

---

## 7. Cambiar el punto de retiro de un pedido de Perú

El botón **Cambiar punto Hop** del pedido (ver la [Guía de uso](guia-usuario.md#42-cambiar-el-punto-de-retiro))
también respeta el Ubigeo: busca los puntos con el Ubigeo del pedido en vez del código
postal, que en el Modo Mapeo puede ni existir. El modal muestra una línea **Ubigeo**,
además de **Código Postal**, para que se vea qué código se usa.

---

## 8. Resumen y diagnóstico

- Todo esto aplica solo a direcciones cuyo país es **Perú**.
- Se configura en **Default** o **Website**, no en Store View.
- En el Modo Mapeo, primero **Importar Ubigeo** y después **Crear campos**.
- Los nombres de provincia y distrito tienen que coincidir exactamente con la tabla
  oficial: conviene usar los campos que crea el botón.

**Si Hop no cotiza en Perú**, revisá:

1. Qué **Origen del Ubigeo** tiene el Website.
2. Si es Mapeo: que la tabla esté importada y que la dirección tenga Provincia y Distrito
   cargados y coincidentes con la tabla.
3. Si es Campo: que el atributo elegido tenga el Ubigeo cargado en la dirección.
