# Sincronización de puntos

Configuración que lo controla: `shipping/hop/client_id`, `shipping/hop/seller_code`, `shipping/hop/pickup_points_api_version`.

## Para qué sirve

Mantiene una copia local de los puntos de retiro por código postal, para no consultar a Hop en cada compra. Un proceso programado la refresca cada hora.

## Dónde vive

- Proceso programado: `Cron/LoadPoints.php` (`execute`, `cacheCoordinatesForPointsWithoutThem`), registrado como `hop_envios_load_points` en `etc/crontab.xml`, con horario `0 * * * *`.
- Búsqueda y caché: `Model/Webservice.php` (`getPickupPoints`, `fetchAllPostalCodes`, `getCoordinatesForZipCode`, `getPickupPointsByCoordinates`).
- Modelo: `Model/Point.php`, tabla `hop_pickup_points`.
- Limpieza: `Setup/Patch/Data/CleanPickupPointsWithoutClientId.php`.

Pruebas: no hay pruebas automatizadas en el repositorio.

## Entradas y salidas

`getPickupPoints` recibe el código postal, el país y si debe ignorar la caché. Devuelve la respuesta de Hop con la lista en `data`.

Escribe `hop_pickup_points`, con una fila por código postal, país y cliente de Hop. Guarda la respuesta completa en `point_data` y, si las tiene, las coordenadas.

## Comportamiento

Búsqueda, usada por la compra y por el proceso programado:

1. Si no hay país, usa el de la tienda y, si tampoco hay, Argentina.
2. Busca la fila por código postal, país y cliente. Si tiene puntos y no se pidió ignorar la caché, devuelve esa copia.
3. Si no, consulta a Hop por código postal, con la versión configurada de la consulta de puntos.
4. Si Hop no devuelve puntos, busca las coordenadas del código postal y consulta por cercanía.
5. Guarda la respuesta en la fila.

Proceso programado, cada hora:

1. Completa las coordenadas que faltan: pide los códigos postales una vez por país y las guarda.
2. Arma un mapa de cliente de Hop a tienda, con la primera tienda de cada cliente.
3. Recorre todas las filas de esos clientes y repite la búsqueda ignorando la caché.

Solo se refrescan códigos postales que alguien ya buscó: el proceso no descarga el catálogo completo de puntos.

## Errores y casos límite

- Ninguna tienda con cliente configurado: el proceso termina sin hacer nada.
- Error en una fila: se registra y sigue con la siguiente.
- Sin códigos postales para un país, o sin coordenadas para un código: se registra y sigue.
- Hop responde sin puntos: se guarda esa respuesta vacía, y la próxima búsqueda vuelve a consultar a Hop porque la copia vacía no sirve.
- Hop no responde: no se toca la fila.
- Error al guardar: se registra y la búsqueda devuelve igual la respuesta.

## Qué otras partes se ven afectadas

- [Cotización de envío](cotizacion-de-envio.md): sin puntos no hay tarifa.
- [Selección de punto de retiro](seleccion-de-punto-de-retiro.md): muestra estos datos.
- [Gestión del pedido en el panel](gestion-del-pedido-en-el-panel.md): el cambio de punto usa la misma búsqueda.
- [Conexión con Hop](conexion-con-hop.md): la cantidad de llamadas por hora crece con la cantidad de filas.
- Sistema externo: Hop.

## Decisiones y pendientes

### Decisiones conocidas

- Las filas se separan por cliente de Hop, y `CleanPickupPointsWithoutClientId` borra las que no lo tienen.
- Las coordenadas se piden por país y en bloque para evitar llamadas repetidas (comentario en `cacheCoordinatesForPointsWithoutThem`).

### Diferencias detectadas

- `getPickupPointsByCoordinates` tiene una lista de radios de 5, 10 y 25 kilómetros, pero corta en el primero: una respuesta válida sin puntos cuenta como resultado. Los radios de 10 y 25 solo se prueban si la respuesta anterior falla.
- `hop_pickup_points.api_id` es obligatorio, sin valor por defecto, y ningún código lo escribe.
- `cacheCoordinatesForPointsWithoutThem` corre antes de fijar una tienda, con las credenciales de la tienda que tenga `Webservice` en ese momento.

### Sin confirmar

- Si la intención de los radios era ampliar la búsqueda cuando no hay puntos. El desarrollador no lo sabe.
- Si `api_id` hace fallar la inserción de filas nuevas según el modo estricto de la base de datos. El error se registraría y no se guardaría la caché.
