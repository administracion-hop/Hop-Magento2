<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;

/**
 * Revisiones que solo leen configuración (2, 3, 4 y 8). Son baratas: Report las calcula al
 * mostrar la franja, así un problema corregido desaparece al recargar la pantalla. El aviso de
 * tienda demo se guarda desde Contenido → Diseño → Configuración, que no dispara el evento de
 * guardado de configuración: por eso no alcanza con recalcular al guardar.
 *
 * Cada método devuelve null si está bien, o [severidad, mensaje].
 */
class ConfigChecks
{
    /**
     * @var HelperHop
     */
    private $helper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param HelperHop $helper
     * @param ScopeConfigInterface $scopeConfig
     * @param ResourceConnection $resource
     */
    public function __construct(
        HelperHop $helper,
        ScopeConfigInterface $scopeConfig,
        ResourceConnection $resource
    ) {
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->resource = $resource;
    }

    /**
     * Todas las revisiones de configuración de una vista de tienda.
     *
     * @param int $storeId
     * @return array<string, array> código => [severidad, mensaje]
     */
    public function run($storeId)
    {
        $results = [
            Checks::CREDENTIALS_MISSING => $this->checkCredentialsMissing($storeId),
            Checks::DISPATCH_DATA => $this->checkDispatchData($storeId),
            Checks::DOCUMENT => $this->checkDocument($storeId),
            Checks::DEMO_MODE => $this->checkDemoMode($storeId),
            Checks::WEIGHT_UNIT => $this->checkWeightUnit($storeId),
        ];
        return array_filter($results);
    }

    /**
     * 1a sin datos: sin credenciales cargadas no hay cuenta que revisar contra Hop, así que el
     * problema se ve leyendo la configuración.
     *
     * @param int $storeId
     * @return array|null
     */
    public function checkCredentialsMissing($storeId)
    {
        $fields = [
            'Client ID' => $this->helper->getClientId($storeId),
            'Client Secret' => $this->helper->getClientSecret($storeId),
            (string)__('email') => $this->helper->getEmail($storeId),
            (string)__('contraseña') => $this->helper->getPassword($storeId),
        ];
        $missing = [];
        foreach ($fields as $label => $value) {
            if ($value === null || trim((string)$value) === '') {
                $missing[] = $label;
            }
        }
        if (!$missing) {
            return null;
        }
        return [
            Checks::SEVERITY_ERROR,
            (string)__('Faltan credenciales de Hop: %1. Sin ellas Hop no aparece en el checkout ni se despacha nada.', implode(', ', $missing)),
        ];
    }

    /**
     * 2. Datos para despachar. Días de preparación 0 es válido.
     *
     * @param int $storeId
     * @return array|null
     */
    public function checkDispatchData($storeId)
    {
        $fields = [
            (string)__('código de vendedor') => $this->helper->getSellerCode($storeId),
            (string)__('código de almacenamiento') => $this->helper->getStorageCode($storeId),
            (string)__('tipo de envío') => $this->helper->getShippingType($storeId),
            (string)__('días de preparación') => $this->helper->getDaysOffset($storeId),
        ];
        $missing = [];
        foreach ($fields as $label => $value) {
            if ($value === null || trim((string)$value) === '') {
                $missing[] = $label;
            }
        }
        if (!$missing) {
            return null;
        }
        return [
            Checks::SEVERITY_ERROR,
            (string)__('Falta completar: %1. Sin estos datos Hop rechaza los despachos.', implode(', ', $missing)),
        ];
    }

    /**
     * 3. Documento del comprador.
     *
     * @param int $storeId
     * @return array|null
     */
    public function checkDocument($storeId)
    {
        if ($this->helper->useCustomerTaxvat($storeId)) {
            if (!$this->helper->IsVAtShowToFrontend($storeId)) {
                return [
                    Checks::SEVERITY_ERROR,
                    (string)__('Se usa el taxvat como documento, pero el campo no se muestra en la tienda: los pedidos llegan sin documento.'),
                ];
            }
            return null;
        }

        $attribute = trim((string)$this->helper->getCustomerDocumentAttribute($storeId));
        if ($attribute === '') {
            return [
                Checks::SEVERITY_ERROR,
                (string)__('No se usa el taxvat y no hay un atributo de documento configurado: los pedidos llegan sin documento.'),
            ];
        }
        $connection = $this->resource->getConnection();
        if (!$connection->tableColumnExists($this->resource->getTableName('sales_order'), $attribute)) {
            return [
                Checks::SEVERITY_ERROR,
                (string)__('El atributo de documento "%1" no existe como columna de los pedidos (sales_order).', $attribute),
            ];
        }
        return null;
    }

    /**
     * 4. Modo de Hop contra tienda demo. Solo advertencia: compara dos configuraciones, sin
     * deducir nada de los pedidos.
     *
     * @param int $storeId
     * @return array|null
     */
    public function checkDemoMode($storeId)
    {
        $isDemo = $this->scopeConfig->isSetFlag('design/head/demonotice', ScopeInterface::SCOPE_STORE, $storeId);
        $isProductive = $this->helper->getProductivo($storeId);
        if (!$isDemo && !$isProductive) {
            return [
                Checks::SEVERITY_WARNING,
                (string)__('Hop está en modo prueba y la tienda no está marcada como demo: si la tienda vende de verdad, los pedidos no se despachan en Hop.'),
            ];
        }
        if ($isDemo && $isProductive) {
            return [
                Checks::SEVERITY_WARNING,
                (string)__('La tienda está marcada como demo y Hop está en modo productivo: los pedidos de prueba se despachan de verdad en Hop.'),
            ];
        }
        return null;
    }

    /**
     * 8. Unidad de peso. El carrier toma el peso como kilos sin convertir.
     *
     * @param int $storeId
     * @return array|null
     */
    public function checkWeightUnit($storeId)
    {
        $unit = $this->scopeConfig->getValue('general/locale/weight_unit', ScopeInterface::SCOPE_STORE, $storeId);
        if ($unit === 'lbs') {
            return [
                Checks::SEVERITY_ERROR,
                (string)__('La tienda usa libras y Hop toma el peso como kilos: las cotizaciones y los despachos van con un peso equivocado.'),
            ];
        }
        return null;
    }
}
