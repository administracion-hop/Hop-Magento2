# Módulo de envíos HOP para Magento 2

[![Build Status](https://travis-ci.org/joemccann/dillinger.svg?branch=master)](https://travis-ci.org/joemccann/dillinger)

## Descripción
Este plugin oficial de [Envíos Hop](https://www.hopenvios.com.ar/) te permite integrar fácilmente el servicio en tu sitio Magento2. Está desarrollado en base al SDK oficial de HOP.

### Instalación
El módulo requiere Magento 2.0.x o superior para su correcto funcionamiento. Se deberá instalar mediante los comandos de consola de Magento.

Instalación en modo de "developer"

```sh
php bin/magento deploy:mode:set developer
php bin/magento module:enable Hop_Envios
php bin/magento setup:upgrade
php bin/magento setup:static-content:deploy es_AR en_US
php bin/magento setup:di:compile
```

Instalación para el modo "production"

```sh
php bin/magento module:enable Hop_Envios
php bin/magento setup:upgrade
php bin/magento deploy:mode:set production
```

### Credenciales de testing
Se deberán solicitar a https://www.hopenvios.com.ar/

### Paso a producción
Se deberán solicitar a https://www.hopenvios.com.ar/
 
## Autor

[![N|Solid](https://www.improntus.com/developed-by-small.png)](https://www.improntus.com)

