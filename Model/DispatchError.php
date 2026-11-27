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
     * Hop rechazó bultos de un multibulto después de crear el envío con los demás. No sale de
     * PATTERNS: lo asigna SalesOrderShipmentSaveAfter, y no se puede reintentar (ver isRetryable).
     */
    const CODE_MULTIBULTO_PARTIAL = 'multibulto_partial';
    /**
     * Hop rechazó el inicio de sesión. Lo asigna quien despacha cuando
     * Webservice::wasRejectedByCredentials(), y también PATTERNS cuando el error trae el texto
     * que arma el propio módulo para ese rechazo ("rechazó las credenciales"). No sale de
     * "Authorization fail" ni de un 401: Hop responde así también cuando acepta el inicio de
     * sesión y rechaza la llamada (por ejemplo, un vendedor que no corresponde al cliente), y
     * marcarlo como credenciales lo reprogramaría con cada inicio de sesión bueno, para siempre.
     * No se reintenta por tiempo: lo reprograma CredentialState, y no cuenta para los escalones
     * de RETRY_DELAYS_MINUTES.
     */
    const CODE_CREDENTIALS = 'credentials';
    /**
     * Despacho que una versión anterior a la tarea 18065 marcó como despachado sin que Hop creara
     * el envío (sin bultos ni número de seguimiento). Lo asigna el parche
     * ReopenDispatchWithoutHopRecord. Se puede reintentar, pero antes de crear el envío el
     * despacho busca en Hop si ya existe uno con la referencia del pedido
     * (SalesOrderShipmentSaveAfter): Hop acepta un segundo multibulto con la misma referencia, así
     * que reintentar sin buscar podía duplicarlo. No se reintenta solo (ver retryDelayMinutes).
     */
    const CODE_UNCONFIRMED_DISPATCH = 'unconfirmed_dispatch';

    /**
     * Espera antes de cada reintento automático de errores transitorios (5XX / sin
     * respuesta), según la política de Hop: 60 s, 10 min, 30 min. Después se frena.
     */
    const RETRY_DELAYS_MINUTES = [1, 10, 30];

    /** substring (case-insensitive) => código. Doc oficial salvo donde se indica. */
    const PATTERNS = [
        'reference id ya est' => self::CODE_REFERENCE_IN_USE,          // 1
        'rechazó las credenciales' => self::CODE_CREDENTIALS,          // del módulo: Webservice::getCredentialsRejectedMessage
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
            'Configurá el "Código postal de origen" en la configuración de Hop (Configuración de webservices HOP) y después reintentá.',
            true,
        ],
        self::CODE_REFERENCE_IN_USE => [
            'Ya existe un envío en Hop con esta referencia y el módulo no pudo recuperarlo solo. Hop no acepta otro envío con la misma referencia, así que no se puede reintentar desde Magento: buscalo en el panel de Hop.',
            false,
        ],
        self::CODE_CREDENTIALS => [
            'Hop rechazó las credenciales de la cuenta. Revisá Client ID, Client Secret, email y contraseña en la configuración de Hop. Cuando vuelvan a funcionar, el pedido se reintenta solo.',
            true,
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
        self::CODE_UNCONFIRMED_DISPATCH => [
            'Una versión anterior del módulo marcó este pedido como despachado aunque Hop no confirmó el envío. Apretá "Enviar a HOP": antes de crear el envío, el módulo busca en Hop si ya existe uno con la referencia del pedido y, si corresponde al pedido, lo recupera sin crear otro.',
            false,
        ],
        self::CODE_MULTIBULTO_PARTIAL => [
            'Hop rechazó uno o más bultos de este pedido. Los bultos aceptados ya están creados en Hop (sus etiquetas están en "Etiquetas HOP") y Hop no admite sumar bultos a un envío ya creado, así que no se puede reintentar desde Magento. Resolvé los bultos rechazados con Hop por fuera de Magento.',
            false,
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
     * Motivo que deja el parche en los despachos marcados como despachados sin envío en Hop.
     *
     * @return string
     */
    public static function unconfirmedDispatchMessage()
    {
        return (string)__(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            'Marcado como despachado sin envío en Hop por una versión anterior del módulo. Al reintentar con "Enviar a HOP", el módulo primero busca en Hop si el envío ya existe.'
        );
    }

    /**
     * Motivo cuando no se pudo buscar en Hop si el envío ya existía: no se creó ningún envío.
     *
     * @return string
     */
    public static function existingSearchFailedMessage()
    {
        return (string)__(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            'No se pudo comprobar si el envío ya existía en Hop (Hop no respondió la búsqueda), así que no se creó ningún envío para no duplicarlo. Reintentá más tarde con "Enviar a HOP".'
        );
    }

    /**
     * Motivo cuando Hop rechazó las credenciales al buscar si el envío ya existía.
     *
     * @return string
     */
    public static function existingSearchCredentialsMessage()
    {
        return (string)__(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            'Hop rechazó las credenciales al buscar si el envío ya existía, así que no se creó ningún envío para no duplicarlo. Revisá las credenciales en la configuración de Hop y reintentá con "Enviar a HOP".'
        );
    }

    /**
     * Motivo cuando Hop tiene envíos con la referencia que no son del pedido (otro correo o punto).
     *
     * @return string
     */
    public static function existingMismatchMessage()
    {
        return (string)__(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            'Hop ya tiene un envío con la referencia de este pedido, pero no corresponde al pedido (otro correo del cliente u otro punto de retiro). No se creó otro envío: buscalo en el panel de Hop.'
        );
    }

    /**
     * Motivo cuando Hop tiene más o menos bultos del pedido que envíos de Magento tiene el pedido.
     *
     * @param int $found bultos en Hop que corresponden al pedido
     * @param int $expected envíos de Magento del pedido
     * @return string
     */
    public static function existingAmbiguousMessage($found, $expected)
    {
        return (string)__(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            'Hop tiene %1 bulto(s) con la referencia de este pedido y el pedido tiene %2 envío(s) de Magento, así que no se puede saber cuál corresponde. No se creó otro envío: revisalo en el panel de Hop.',
            (int)$found,
            (int)$expected
        );
    }

    /**
     * Motivo de un multibulto que Hop aceptó a medias, con cuántos bultos rechazó.
     *
     * Lo usan el despacho (SalesOrderShipmentSaveAfter) y el parche que repara los multibultos
     * trabados (RepairStuckMultibultoDispatch), para que el mismo estado tenga el mismo texto.
     * Cuenta solo los bultos que se mandaron a Hop, no los envíos agregados después.
     *
     * @param int $rejected bultos rechazados
     * @param int $sent bultos mandados a Hop
     * @return string
     */
    public static function multibultoPartialMessage($rejected, $sent)
    {
        return (string)__(
            'Ya creado en Hop con bultos rechazados: %1 de %2 bultos rechazados por Hop.',
            (int)$rejected,
            (int)$sent
        );
    }

    /**
     * Si el despacho se puede volver a intentar (solo o con "Enviar a HOP").
     *
     * @param string|null $code
     * @return bool
     */
    public static function isRetryable($code)
    {
        // Referencia en uso: Hop ya tiene un envío con la referencia del pedido y rechaza
        // cualquier otro, así que reintentar no sirve y "Enviar a HOP" no se ofrece.
        return $code !== self::CODE_MULTIBULTO_PARTIAL && $code !== self::CODE_REFERENCE_IN_USE;
    }

    /**
     * Minutos hasta el próximo reintento automático, o null si no hay que reintentar solo.
     * Hop pide reintentar sólo errores del servidor (5XX) y frenar ante un 429. Cualquier otra
     * respuesta (4XX, o un 2XX/3XX sin envío) o un error conocido son datos a corregir:
     * reintentar da el mismo rechazo hasta que alguien los arregle, así que quedan para
     * revisión manual.
     *
     * Credenciales (CODE_CREDENTIALS) también devuelve null: ese reintento no es por tiempo.
     *
     * @param int $attempts intentos fallidos contando el actual
     * @param int|null $httpStatus null/0 = sin respuesta (red, timeout) o excepción antes de llegar a Hop
     * @param string|null $code
     * @return int|null
     */
    public static function retryDelayMinutes($attempts, $httpStatus, $code)
    {
        if ($code || ($httpStatus && $httpStatus < 500)) {
            return null;
        }
        return self::RETRY_DELAYS_MINUTES[$attempts - 1] ?? null;
    }
}
