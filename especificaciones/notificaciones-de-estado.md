# Notificaciones de estado

Configuración que lo controla: `shipping/hop/allow_notifications`, `carriers/hop/statuses/allow_notifications`, `carriers/hop/statuses/processing/*`, `carriers/hop/statuses/complete/*`, `shipping/hop/seller_code`.

## Para qué sirve

Recibe de Hop los cambios de estado de un envío y los refleja en el pedido de Magento, para que el operador vea en qué etapa está sin entrar a Hop.

## Dónde vive

- Servicio: `Api/WebhookManagementInterface.php`, `Model/WebhookManagement.php` (`processNotification`, `processData`), `Api/Data/WebhookResponseInterface.php`, `Model/Data/WebhookResponse.php`.
- Ruta y permiso: `etc/webapi.xml`, `etc/acl.xml`.
- Cambio de estado: `Helper/StatusManager.php` (`processOrder`, `getSelectedStatus`).
- Códigos de Hop: `Model/EcomStatuses.php`.
- Pantalla de equivalencias: `etc/adminhtml/system.xml`, `Model/Config/Source/AbstractStatus.php`, `Model/Config/Source/ProcessingStatus.php`, `Model/Config/Source/CompleteStatus.php`.
- Instalación: `Setup/Patch/Data/CreateStatuses.php`, `Setup/Patch/Data/CreateHopIntegration.php`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

Recibe un aviso entrante (webhook) por `POST /V1/hop-envios/notifications`, protegido por el permiso `Hop_Envios::webhook_notifications`. Usa `reference_id`, `seller_code`, `tracking_nro` y `tracking.last_status` (`code`, `state`, `description`).

Devuelve `success` y `message`. Escribe el estado del pedido y una entrada en su historial, sin notificar al comprador.

## Comportamiento

1. Verifica que las notificaciones estén habilitadas.
2. Verifica que haya datos y `reference_id`.
3. Compara `seller_code` con el configurado.
4. Si falta el código de estado, termina sin cambios y responde éxito.
5. Normaliza el código, cambiando guiones por guiones bajos, y arma el comentario.
6. Quita el código de vendedor de la referencia y busca el pedido por número.
7. Si el código está en `EcomStatuses`, busca la equivalencia para el estado interno del pedido (`processing` o `complete`). Si no la hay, conserva el estado actual.
8. Verifica que el estado nuevo sea válido para el estado interno del pedido.
9. Guarda el estado y agrega el comentario. No cambia el estado interno.

Instalación: `CreateStatuses` crea un estado de Magento por cada código de Hop y por cada estado interno (`processing_E_CON`, `complete_E_CON`, y así). `CreateHopIntegration` crea la integración "Hop Integration", activa y con su token, al correr la actualización del módulo.

## Errores y casos límite

- Notificaciones apagadas, sin datos, sin referencia, vendedor distinto, pedido no encontrado o estado no permitido: se registra y responde `success: false`.
- Código desconocido: conserva el estado y agrega el comentario.
- Pedido en otro estado interno, por ejemplo cancelado: conserva el estado y agrega el comentario.
- Falla al crear la integración: se registra y la instalación sigue.

## Qué otras partes se ven afectadas

- [Despacho de pedidos](despacho-de-pedidos.md): define la referencia con la que se encuentra el pedido.
- Guardar el pedido dispara `SalesOrderSaveAfter`, así que un estado equivalente que esté en `status_allowed` podría registrar el pedido para despacho.
- Sistema externo: Hop, que necesita las claves de la integración para llamar a la ruta.

## Decisiones y pendientes

### Decisiones conocidas

- La integración se crea por código en la instalación (`CreateHopIntegration`), con los permisos `Magento_Backend::admin`, `Hop_Envios::webhook_notifications`, `Magento_Webapi::system`, `Magento_Integration::integration`, `Magento_Backend::store` y `Magento_Backend::web`. Esto se dedujo del código, a pedido del desarrollador.

### Diferencias detectadas

- `Helper/Data.php` (`getAllowNotifications`) lee `shipping/hop/allow_notifications`, pero el panel guarda en `carriers/hop/statuses/allow_notifications`. Con eso, toda notificación se rechaza y las equivalencias no tienen efecto.
- La respuesta se devuelve igual cuando hay error, así que el código de estado que recibe Hop indica éxito aunque `success` sea falso.
- La documentación interna habla de crear a mano una integración con otro nombre y con permisos sobre pedidos y configuración. El parche la crea sola, con el nombre y los permisos de arriba.
- `getSellerCode` se lee sin tienda, es decir, con el valor por defecto.
- `CreateStatuses` comprueba si existe el código sin prefijo, pero guarda el estado con prefijo, así que esa comprobación nunca lo encuentra.
- No se avisa al comprador de ningún cambio, ni siquiera cuando el pedido está listo para retirar.

### Sin confirmar

- Si hay tiendas con varios sitios web y códigos de vendedor distintos, que serían rechazadas por la comparación contra el valor por defecto. El desarrollador no lo sabe.
- Si Hop reintenta una notificación que recibió `success: false`.
