# Módulo de envíos HOP para Magento 2

Este plugin oficial de **Envíos Hop** permite integrar fácilmente el servicio en tu sitio **Magento 2**. Está desarrollado sobre la base del SDK oficial de HOP, asegurando compatibilidad y facilidad de uso.

## Requisitos

- Magento 2.0.x o superior.

## Instalación

   ```bash
   composer require hopenvios/magento2
   ```

### Modo de Desarrollador

Para habilitar el módulo en modo de desarrollador, sigue estos pasos:

1. Configura el modo de desarrollador:
   ```bash
   php bin/magento deploy:mode:set developer
   ```

2. Habilita el módulo:
   ```bash
   php bin/magento module:enable Hop_Envios
   ```

3. Actualiza la configuración del módulo:
   ```bash
   php bin/magento setup:upgrade
   ```

4. Despliega el contenido estático:
   ```bash
   php bin/magento setup:static-content:deploy es_AR en_US
   ```

5. Compila el código de dependencias:
   ```bash
   php bin/magento setup:di:compile
   ```

### Modo de Producción

Para habilitar el módulo en modo de producción, sigue estos pasos:

1. Habilita el módulo:
   ```bash
   php bin/magento module:enable Hop_Envios
   ```

2. Actualiza la configuración del módulo:
   ```bash
   php bin/magento setup:upgrade
   ```

3. Configura el modo de producción:
   ```bash
   php bin/magento deploy:mode:set production
   ```

## Credenciales
Las credenciales para el entorno de testing y el entorno productivo deben ser solicitadas a [HOP Envíos](https://www.hopenvios.com.ar/).

---

### Autor

[HOP Envíos](https://www.hopenvios.com.ar/)