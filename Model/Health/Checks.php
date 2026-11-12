<?php

namespace Hop\Envios\Model\Health;

/**
 * Revisiones del verificador de configuración y conexión con Hop (tarea 12908).
 *
 * Hay tres familias, según dónde se calculan (ver docs/verificador-guia.md):
 * - Contra Hop o el catálogo (REMOTE): las corre Runner en la tarea de 6 horas y al guardar la
 *   configuración de Hop, y guarda el resultado en hop_envios_health_check. Las credenciales
 *   además las actualizan las llamadas normales a Hop (ver CredentialState).
 * - Solo configuración (LIVE_CONFIG): las calcula Report al mostrar la franja.
 * - Consultas a la base (LIVE_DB): las calcula Report al mostrar la franja, con caché corta.
 */
class Checks
{
    const SEVERITY_ERROR = 'error';
    const SEVERITY_WARNING = 'warning';
    const STATUS_OK = 'ok';

    const CREDENTIALS = 'credentials';       // 1a
    const SELLER = 'seller';                 // 1b
    const CONNECTION = 'connection';         // 1c
    const DISPATCH_DATA = 'dispatch_data';   // 2
    const DOCUMENT = 'document';             // 3
    const DEMO_MODE = 'demo_mode';           // 4
    const TEST_QUOTE = 'test_quote';         // 5
    const CRON = 'cron';                     // 6
    const NOTIFICATIONS = 'notifications';   // 7
    const WEIGHT_UNIT = 'weight_unit';       // 8
    const MEASURES = 'measures';             // 9

    /** Se calculan contra Hop o el catálogo y se guardan. */
    const REMOTE = [self::CREDENTIALS, self::SELLER, self::CONNECTION, self::TEST_QUOTE, self::MEASURES];

    /** Se calculan al mostrar la franja leyendo solo configuración. */
    const LIVE_CONFIG = [self::DISPATCH_DATA, self::DOCUMENT, self::DEMO_MODE, self::WEIGHT_UNIT];

    /** Se calculan al mostrar la franja con una consulta a la base y caché corta. */
    const LIVE_DB = [self::CRON, self::NOTIFICATIONS];

    /** Orden en el que se muestran en el detalle. */
    const ORDER = [
        self::CREDENTIALS, self::SELLER, self::CONNECTION, self::DISPATCH_DATA, self::DOCUMENT,
        self::DEMO_MODE, self::TEST_QUOTE, self::CRON, self::NOTIFICATIONS, self::WEIGHT_UNIT,
        self::MEASURES,
    ];

    /** Scope de las revisiones que no dependen de una tienda (tarea programada). */
    const SCOPE_GLOBAL = 'global';

    /**
     * @param int $storeId
     * @return string
     */
    public static function storeScope($storeId)
    {
        return 'store:' . (int)$storeId;
    }

    /**
     * @param string $code
     * @return string
     */
    public static function getTitle($code)
    {
        $titles = [
            self::CREDENTIALS => __('Credenciales de Hop'),
            self::SELLER => __('Vendedor en Hop'),
            self::CONNECTION => __('Conexión con Hop'),
            self::DISPATCH_DATA => __('Datos para despachar'),
            self::DOCUMENT => __('Documento del comprador'),
            self::DEMO_MODE => __('Modo de Hop y tienda demo'),
            self::TEST_QUOTE => __('Cotización de prueba'),
            self::CRON => __('Tarea programada de despacho'),
            self::NOTIFICATIONS => __('Avisos de estado de Hop'),
            self::WEIGHT_UNIT => __('Unidad de peso'),
            self::MEASURES => __('Medidas de los productos'),
        ];
        return isset($titles[$code]) ? (string)$titles[$code] : $code;
    }

    /**
     * Cómo resolver cada problema.
     *
     * @param string $code
     * @return string
     */
    public static function getFix($code)
    {
        $fixes = [
            self::CREDENTIALS => __('Revisá Client ID, Client Secret, email y contraseña en Tiendas → Configuración → Ventas → Métodos de envío → Configuración de webservices HOP, y que el modo (prueba o productivo) corresponda a esas credenciales. El aviso se va solo apenas una llamada a Hop funciona, y los pedidos que fallaron por esto se reintentan solos.'),
            self::SELLER => __('Pedile a Hop que active el vendedor, o revisá que el código de vendedor de la configuración sea el que te dio Hop.'),
            self::CONNECTION => __('Puede ser una caída momentánea de Hop o un problema de red del servidor. Si sigue en la próxima revisión, revisá que el servidor pueda salir a api.hopenvios.com.ar.'),
            self::DISPATCH_DATA => __('Completá código de vendedor, código de almacenamiento, tipo de envío y días de preparación en la configuración de webservices HOP. Días de preparación puede ser 0.'),
            self::DOCUMENT => __('Si usás el taxvat, mostralo en la tienda desde Tiendas → Configuración → Clientes → Configuración de clientes → Crear cuenta nueva → Mostrar número de IVA en el frontend. Si no, cargá en "Atributo de documento" el nombre de una columna de los pedidos que tenga el DNI.'),
            self::DEMO_MODE => __('Si la tienda vende de verdad, pasá Hop a modo productivo con las credenciales productivas. Si es una tienda de prueba, marcala como demo en Contenido → Diseño → Configuración → HTML Head → Mostrar aviso de demo, o pasá Hop a modo prueba.'),
            self::TEST_QUOTE => __('Revisá el código postal de origen, el código de vendedor y el tamaño de categoría en la configuración de Hop. Si todo está bien, consultá a Hop con el mensaje del detalle.'),
            self::CRON => __('Revisá que el cron de Magento esté corriendo en el servidor (bin/magento cron:run cada minuto).'),
            self::NOTIFICATIONS => __('Creá o activá una integración en Sistema → Extensiones → Integraciones con el permiso Hop Envios → Webhook Notifications, y pasale el token a Hop. Si no usás los avisos de estado, desactivalos en Métodos de envío → Envios HOP → Manejo de estado de las ventas.'),
            self::WEIGHT_UNIT => __('Cambiá la unidad de peso a kilos en Tiendas → Configuración → General → Opciones de configuración regional, y revisá el peso de los productos.'),
            self::MEASURES => __('Elegí un tamaño de categoría en la configuración de Hop, o completá alto, ancho y largo en los productos.'),
        ];
        return isset($fixes[$code]) ? (string)$fixes[$code] : '';
    }
}
