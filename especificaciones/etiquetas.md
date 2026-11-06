# Etiquetas

Configuración que lo controla: `shipping/hop/type_label`, `shipping/hop/label_size`.

## Para qué sirve

Pone a disposición del operador la etiqueta de cada bulto para imprimirla. Hop entrega la dirección de la etiqueta enseguida, pero el archivo tarda en estar disponible, así que el módulo reintenta solo.

## Dónde vive

- Generación: `Model/Shipping/NativeLabelGenerator.php` (`generate`).
- Reintento: `Cron/GenerateShippingLabels.php`, registrado como `hop_envios_generate_shipping_labels` en `etc/crontab.xml`, cada minuto.
- Candidatos al reintento: `Model/HopEnviosShipmentRepository.php` (`getCompletedWithLabel`).
- Datos para Magento: `Model/Carrier/Hop.php` (`_doShipmentRequest`).
- Conversión a PDF (formato de documento portátil): `Plugin/Shipping/LabelGeneratorPlugin.php` (`aroundCreatePdfPageFromImageString`, `beforeCombineLabelsPdf`).
- Descarga: `Controller/Adminhtml/Label/Download.php`.
- Listado de etiquetas de un pedido: `Controller/Adminhtml/Order/Labels.php`, `Block/Adminhtml/Order/HopLabelsView.php`, `view/adminhtml/templates/order/labels-view.phtml`, `view/adminhtml/templates/order/view/labels-js.phtml`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Recibe el `label_url` guardado en `hop_envios_shipment`, o en `hop_envios.info_hop` para pedidos anteriores a esa tabla.

Escribe `sales_shipment.shipping_label`, que es lo que usa Magento para imprimir, también en forma masiva desde la grilla. La descarga entrega el archivo al operador.

## Comportamiento

Generación:

1. El despacho llama a `generate` apenas Hop acepta. Si el envío ya tiene etiqueta, no hace nada.
2. `generate` no pasa por `LabelGenerator::create`, que exige una sesión de administrador, para poder correr desde procesos programados.
3. Pide los datos al método de envío. `_doShipmentRequest` toma el seguimiento y la etiqueta del registro del bulto y, en pedidos sin registros, de `info_hop`.
4. `beforeCombineLabelsPdf` descarga la imagen, normaliza los colores y la convierte a PDF.
5. Guarda el resultado en el envío.

Reintento:

1. Cada minuto toma los registros `completed` con etiqueta creados en los últimos 60 minutos.
2. Salta los envíos que ya tienen etiqueta y llama a `generate` en los demás.

Descarga:

1. Verifica el permiso `Hop_Envios::label_download`.
2. Busca la dirección por envío o por pedido, descarga el archivo y detecta su tipo.
3. Las imágenes se convierten a PDF; si la conversión falla, entrega el original.
4. Las etiquetas ZPL (lenguaje de impresoras de etiquetas) no pasan por este controlador: el panel abre su dirección directamente.

## Errores y casos límite

- El archivo todavía no está disponible: la descarga falla, se lanza una excepción, `generate` devuelve `false` y el proceso programado reintenta.
- Pasados los 60 minutos: no se reintenta más, y queda para revisar a mano.
- Pedido despachado por "Enviar a HOP" sin envío de Magento: no hay registro de bulto, así que el proceso programado no lo toma.
- Envío agregado después del despacho: `_doShipmentRequest` da error para que no herede los datos de otro bulto.
- Error en la descarga manual: muestra un mensaje y vuelve a la pantalla anterior.

## Qué otras partes se ven afectadas

- [Despacho de pedidos](despacho-de-pedidos.md): crea los registros y llama a `generate`. Guardar la etiqueta vuelve a guardar el envío, y por eso `dispatch` necesita su control de envíos ya procesados.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): muestra los botones.
- Otros métodos de envío de la tienda: `LabelGeneratorPlugin` se aplica a toda la generación de etiquetas de Magento.
- Sistema externo: Hop, que aloja los archivos.

## Decisiones y pendientes

### Decisiones conocidas

- Una descarga fallida tiene que lanzar excepción: antes se guardaba un PDF vacío que frenaba los reintentos para siempre (comentario en `beforeCombineLabelsPdf`).
- El reintento se limita a 60 minutos para no acumular fallos permanentes (comentario en `getCompletedWithLabel`).

### Diferencias detectadas

- `Hop_Envios::label_download` no está definido en `etc/acl.xml`. Magento trata un recurso inexistente como una consulta general, así que solo el superadministrador pasa el control.
- `docs/multibulto-guia-admin.md` dice que el permiso se habilita en los roles; no aparece en el árbol de permisos para asignarlo.
- La conversión a PDF está repetida en `LabelGeneratorPlugin` y en `Download`, con tamaños de página distintos.
- `aroundCreatePdfPageFromImageString` descarga sin límite de tiempo; `beforeCombineLabelsPdf` usa 10 segundos.

### Sin confirmar

- Si con formato ZPL el reintento falla cada minuto durante una hora: `beforeCombineLabelsPdf` trata el archivo como imagen y no lo sería. El desarrollador no lo vio pasar.
- Si `aroundCreatePdfPageFromImageString` afecta etiquetas de otros transportistas: valida la extensión antes de comprobar que el pedido sea de Hop. El desarrollador no conoce casos.
