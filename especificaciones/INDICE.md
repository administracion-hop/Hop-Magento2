# Índice de especificaciones

Cada especificación describe cómo funciona hoy un componente. Al empezar una tarea se leen las de los componentes que toca. Si un cambio modifica un comportamiento, la especificación se actualiza en el mismo pull request.

## Compra

- [Cotización de envío](cotizacion-de-envio.md): ofrece Hop en la compra y calcula el precio con los ajustes de la tienda.
- [Selección de punto de retiro](seleccion-de-punto-de-retiro.md): deja elegir el punto en un mapa, conserva la elección y la pasa al pedido.
- [Sincronización de puntos](sincronizacion-de-puntos.md): mantiene una copia local de los puntos y la refresca cada hora.
- [Direcciones de Perú](direcciones-de-peru.md): obtiene el código de ubicación que Hop usa en Perú en lugar del código postal.
- [Compatibilidad con Amasty](compatibilidad-con-amasty.md): adapta el selector de puntos a Amasty One Step Checkout.

## Despacho

- [Despacho de pedidos](despacho-de-pedidos.md): avisa a Hop que un pedido sale, con uno o varios bultos.
- [Reintentos de despacho](reintentos-de-despacho.md): clasifica los rechazos de Hop y reintenta los errores transitorios.
- [Etiquetas](etiquetas.md): genera, reintenta y descarga las etiquetas de cada bulto.

## Después del despacho

- [Notificaciones de estado](notificaciones-de-estado.md): recibe los cambios de estado de Hop y los refleja en el pedido.
- [Seguimiento para el comprador](seguimiento-para-el-comprador.md): muestra el enlace de seguimiento en la cuenta del comprador.

## Transversales

- [Conexión con Hop](conexion-con-hop.md): autenticación, ambiente y llamadas a Hop.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): botones, información y cambio de punto en el pedido.
