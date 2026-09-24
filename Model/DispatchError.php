<?php

namespace Hop\Envios\Model;

/**
 * Traduce el texto libre que devuelve la API de Hop a una clave conocida y a la
 * instrucción que ve el admin para arreglarlo antes de reintentar, y decide si
 * vale la pena reintentar solo.
 *
 * Errores y política de reintentos según la doc oficial de Hop:
 * https://developers.hopenvios.net/docs/ar/API/codigos_respuesta_error
 * https://developers.hopenvios.net/docs/ar/API/codigos_respuesta_error_reintentos
 *
 * ponytail: matcheo por substring porque la API no devuelve códigos. Para sumar un
 * error conocido, agregar una fila en PATTERNS y su texto en INSTRUCTIONS.
 */
class DispatchError
{
    const CODE_ORIGIN_ZIP = 'origin_zip';
    const CODE_REFERENCE_IN_USE = 'reference_in_use';
    const CODE_PACKAGE_SIZE = 'package_size';
    const CODE_PACKAGE_VALUE = 'package_value';
    const CODE_CLIENT_ID = 'client_id';
    const CODE_CLIENT_DATA = 'client_data';
    const CODE_NO_PICKUP_POINT = 'no_pickup_point';
    const CODE_PICKUP_DISABLED = 'pickup_disabled';
    const CODE_CONFIG_MISSING = 'config_missing';

    /**
     * Espera antes de cada reintento automático de errores transitorios (5XX / sin
     * respuesta), según la política de Hop: 60 s, 10 min, 30 min. Después se frena.
     */
    const RETRY_DELAYS_MINUTES = [1, 10, 30];

    /** substring (case-insensitive) => código. Doc oficial salvo donde se indica. */
    const PATTERNS = [
        'reference id ya est' => self::CODE_REFERENCE_IN_USE,          // 1
        'client.id number' => self::CODE_CLIENT_ID,                    // 2
        'client.name' => self::CODE_CLIENT_DATA,                       // 4
        'client.telephone' => self::CODE_CLIENT_DATA,                  // 5
        'days offset' => self::CODE_CONFIG_MISSING,                    // 6
        'size category' => self::CODE_PACKAGE_SIZE,                    // 7
        'package.height' => self::CODE_PACKAGE_SIZE,                   // 7
        'package.length' => self::CODE_PACKAGE_SIZE,                   // 7
        'package.width' => self::CODE_PACKAGE_SIZE,                    // 7
        'package.value' => self::CODE_PACKAGE_VALUE,                   // 8
        'package.weight' => self::CODE_PACKAGE_VALUE,                  // 8
        'pickup point id es obligatorio' => self::CODE_NO_PICKUP_POINT, // 9
        'seller code' => self::CODE_CONFIG_MISSING,                    // 10
        'storage code' => self::CODE_CONFIG_MISSING,                   // 10
        'shipping type' => self::CODE_CONFIG_MISSING,                  // 11
        'pickup point está deshabilitado' => self::CODE_PICKUP_DISABLED, // 12
        'No se puede obtener el pickup point' => self::CODE_PICKUP_DISABLED, // 12
        'sender.zip' => self::CODE_ORIGIN_ZIP,                         // visto en sandbox, no está en la doc
        'postal de origen' => self::CODE_ORIGIN_ZIP,                   // de la tarea, no está en la doc
        'No Hop Data' => self::CODE_NO_PICKUP_POINT,                   // del módulo: la orden no tiene punto
    ];

    /** código => [instrucción, ¿lleva link a la config de Hop?] */
    const INSTRUCTIONS = [
        self::CODE_ORIGIN_ZIP => [
            'Configurá el código postal de origen en Tiendas → Configuración → Métodos de envío → Hop → "Código postal de origen" y después reintentá.',
            true,
        ],
        self::CODE_REFERENCE_IN_USE => [
            'Ya existe un envío en Hop con esta referencia y el módulo no pudo recuperarlo solo. Buscalo en el panel de Hop antes de reintentar para no duplicarlo.',
            false,
        ],
        self::CODE_PACKAGE_SIZE => [
            'Hop no recibió las medidas del paquete. Elegí un "Tamaño de categoría" en la configuración de Hop, o completá alto, largo y ancho en los productos, y reintentá.',
            true,
        ],
        self::CODE_PACKAGE_VALUE => [
            'Hop no recibió el valor o el peso del paquete. Revisá que los productos del pedido tengan precio y peso cargados y reintentá.',
            false,
        ],
        self::CODE_CLIENT_ID => [
            'Falta el DNI del comprador. Revisá "Utilizar taxvat como número de documento" en la configuración de Hop y que el pedido tenga el documento cargado.',
            true,
        ],
        self::CODE_CLIENT_DATA => [
            'Al pedido le falta el nombre o el teléfono del comprador. Completalos en la dirección de facturación del pedido y reintentá.',
            false,
        ],
        self::CODE_NO_PICKUP_POINT => [
            'El pedido no tiene punto de retiro asignado. Usá "Cambiar punto Hop" para elegir uno y reintentá.',
            false,
        ],
        self::CODE_PICKUP_DISABLED => [
            'El punto de retiro elegido está deshabilitado en Hop y no acepta pedidos. Usá "Cambiar punto Hop" para asignar otro y reintentá.',
            false,
        ],
        self::CODE_CONFIG_MISSING => [
            'Falta un dato obligatorio en la configuración de Hop: código de vendedor (lo da Hop con las credenciales), código de almacenamiento (DEPOSITO), tipo de envío o días de preparación. Completalo y reintentá.',
            true,
        ],
    ];

    /**
     * @param string|null $message
     * @return string|null
     */
    public static function classify($message)
    {
        foreach (self::PATTERNS as $needle => $code) {
            if ($message && mb_stripos($message, $needle) !== false) {
                return $code;
            }
        }
        return null;
    }

    /**
     * @param string|null $code
     * @return array|null [instrucción, lleva link a config]
     */
    public static function getInstruction($code)
    {
        return self::INSTRUCTIONS[$code] ?? null;
    }

    /**
     * Minutos hasta el próximo reintento automático, o null si no hay que reintentar solo.
     * Hop pide reintentar sólo errores del servidor (5XX) y frenar ante un 429. Un 4XX o un
     * error conocido son datos a corregir: reintentar da el mismo rechazo hasta que alguien
     * los arregle, así que quedan para revisión manual.
     *
     * @param int $attempts intentos fallidos contando el actual
     * @param int|null $httpStatus null/0 = sin respuesta (red, timeout)
     * @param string|null $code
     * @return int|null
     */
    public static function retryDelayMinutes($attempts, $httpStatus, $code)
    {
        if ($code || ($httpStatus >= 400 && $httpStatus < 500)) {
            return null;
        }
        return self::RETRY_DELAYS_MINUTES[$attempts - 1] ?? null;
    }
}
