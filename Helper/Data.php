<?php

namespace Hop\Envios\Helper;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\DataObject\Copy\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Directory\Model\Region;
use Magento\Shipping\Helper\Data as ShippingData;
use Hop\Envios\Logger\LoggerInterface;
use Magento\Framework\Module\Manager as ModuleManager;

/**
 * Class Data
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Helper
 */
class Data extends AbstractHelper
{
    /**
     * @var ScopeConfigInterface
     */
    protected $_scopeConfig;

    /**
     * @var DirectoryList
     */
    protected $_directoryList;

    /**
     * @var StoreManagerInterface
     */
    protected $_storeManagerInterface;

    /**
     * @var Region
     */
    protected $_region;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var Config
     */
    protected $fieldsetConfig;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var ShippingData
     */
    protected $_shippingData;

    /**
     * @var ModuleManager
     */
    protected $moduleManager;


    /**
     * Data constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManagerInterface
     * @param Region $region
     * @param Session $checkoutSession
     * @param LoggerInterface $logger
     * @param Config $fieldsetConfig
     * @param CartRepositoryInterface $cartRepository
     * @param ShippingData $shippingHelper
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManagerInterface,
        Region $region,
        Session $checkoutSession,
        LoggerInterface $logger,
        Config $fieldsetConfig,
        CartRepositoryInterface $cartRepository,
        ShippingData $shippingHelper,
        ModuleManager $moduleManager
    ) {
        $this->_scopeConfig             = $scopeConfig;
        $this->_storeManagerInterface   = $storeManagerInterface;
        $this->_region                  = $region;
        $this->_checkoutSession         = $checkoutSession;
        $this->fieldsetConfig           = $fieldsetConfig;
        $this->logger                   = $logger;
        $this->quoteRepository          = $cartRepository;
        $this->_shippingData            = $shippingHelper;
        $this->moduleManager            = $moduleManager;
    }

    /**
     * @param string $path
     * @param int|null $storeId
     * @return mixed
     */
    protected function getConfigValue(string $path, $storeId = null)
    {
        return $this->_scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getClientId($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/client_id', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getClientSecret($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/client_secret', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/api_key', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getEmail($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/email', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getPassword($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/password', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function useCustomerTaxvat($storeId = null)
    {
        return (bool)$this->getConfigValue('shipping/hop/use_customer_taxvat', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getCustomerDocumentAttribute($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/customer_document_attribute', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isActive($storeId = null)
    {
        return (bool)$this->getConfigValue('carriers/hop/active', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function getAllowNotifications($storeId = null)
    {
        return (bool)$this->getConfigValue('shipping/hop/allow_notifications', $storeId);
    }

    /**
     * @param string $path
     * @param array $params
     * @param int|null $storeId
     * @return string
     * @throws NoSuchEntityException
     */
    public function getStoreUrl($path, $params, $storeId = null)
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->_storeManagerInterface->getStore($storeId);
        return $store->getUrl($path, $params);
    }

    /**
     * @param int|null $storeId
     * @return float
     */
    public function getMaxWeight($storeId = null)
    {
        return (float)$this->getConfigValue('carriers/hop/max_package_weight', $storeId);
    }

    /**
     * @param $regionId
     * @return string
     */
    public function getProvincia($regionId)
    {
        if(is_int($regionId))
        {
            $provincia = $this->_region->load($regionId);

            $regionId = $provincia->getDefaultName() ? $provincia->getDefaultName() : $regionId;
        }

        return $regionId;
    }

    /**
     * @return Session
     */
    public function getSession()
    {
        return $this->_checkoutSession;
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function getProductivo($storeId = null)
    {
        return (boolean) $this->getConfigValue('shipping/hop/modo_productivo', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getPickupPointsApiVersion($storeId = null)
    {
        $apiVersion = $this->getConfigValue('shipping/hop/pickup_points_api_version', $storeId);

        $apiVersion = is_string($apiVersion) ? trim($apiVersion) : '';
        $allowedVersions = ['v1', 'v3'];

        if (in_array($apiVersion, $allowedVersions, true)) {
            return $apiVersion;
        }

        return 'v1';
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getOriginZipcode($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/origin_zipcode', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getSellerCode($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/seller_code', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string[]
     */
    public function getStatusOrderAllowed($storeId = null)
    {
        $statusOrderAllowed = $this->getConfigValue('shipping/hop/status_allowed', $storeId);
        if (empty($statusOrderAllowed)) {
            return [];
        }
        return explode(',', $statusOrderAllowed);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getShippingType($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/shipping_type', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getLabelType($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/type_label', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getLabelSize($storeId = null)
    {
        $labelSize = $this->getConfigValue('shipping/hop/label_size', $storeId);
        return $labelSize ? $labelSize : \Hop\Envios\Model\Config\Source\LabelSizeOption::SIZE_SMALL;
    }

    /**
     * @param int|null $storeId
     * @return int
     */
    public function getDaysOffset($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/days_offset', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return int
     */
    public function getSizeCategory($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/size_category', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getStorageCode($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/storage_code', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function getValidateClientId($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/validate_client_id', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getUbigeoField($storeId = null)
    {
        return $this->getConfigValue('shipping/hop/ubigeo_field', $storeId);
    }

    /**
     * Returns the ubigeo value from the given address if a ubigeo field is configured,
     * or null if not configured or the field is empty.
     *
     * @param \Magento\Framework\DataObject $address
     * @param int|null $storeId
     * @return string|null
     */
    public function getUbigeoFromAddress($address, $storeId = null)
    {
        $ubigeoField = $this->getUbigeoField($storeId);
        if (!$ubigeoField || !$address) {
            return null;
        }
        if ($address->getData('country_id') !== 'PE') {
            return null;
        }
        $value = $address->getData($ubigeoField);
        return ($value !== null && $value !== '') ? (string)$value : null;
    }

    /**
     * @param string $code
     * @param int|null $storeId
     * @return string
     */
    public function getMeasureCode($code, $storeId = null)
    {
        $attributeCode = $this->getConfigValue('shipping/hop/measure_attribute_' . $code, $storeId);
        if (empty($attributeCode)) {
            return "hop_" . $code;
        }
        return $attributeCode;
    }

    /**
     * @param $mensaje String
     * @param $archivo String
     */
    public function log($mensaje, $isError = false, $isArray = false)
    {

        if($isArray){
            $mensaje = print_r($mensaje, true);
        }

        if($isError){
            $this->logger->error($mensaje);
        } else {
            $this->logger->info($mensaje);
        }
    }

    /**
     * @return Quote
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getQuote()
    {
        return $this->_checkoutSession->getQuote();
    }

    /**
     * @param $order
     * @param int|null $storeId
     * @return array
     */
    public function getPackageData($order, $storeId = null)
    {
        $storeId = $storeId ?? $order->getStoreId();
        $package = [];
        $hopAltoTotal = 0;
        $hopLargoTotal = [];
        $hopAnchoTotal = [];
        $totalPrice = 0;
        $weightTotal = 0;

        foreach($order->getAllItems() as $_item)
        {
            if($_item->getProductType() == 'configurable')
                continue;

            $_product = $_item->getProduct();

            if($_item->getParentItem())
                $_item = $_item->getParentItem();

            $hopAlto = (int) $_product->getResource()
                    ->getAttributeRawValue($_product->getId(), $this->getMeasureCode('alto', $storeId), $_product->getStoreId()) * $_item->getQtyOrdered();
            $hopAltoTotal += $hopAlto;

            $hopLargo = (int) $_product->getResource()
                    ->getAttributeRawValue($_product->getId(), $this->getMeasureCode('largo', $storeId), $_product->getStoreId()) * $_item->getQtyOrdered();
            $hopLargoTotal[] = $hopLargo;

            $hopAncho = (int) $_product->getResource()
                    ->getAttributeRawValue($_product->getId(), $this->getMeasureCode('ancho', $storeId), $_product->getStoreId()) * $_item->getQtyOrdered();
            $hopAnchoTotal[] = $hopAncho;

            $weightTotal += ($_product->getWeight() * 1000) * $_item->getQtyOrdered();

            $totalPrice += $_product->getFinalPrice();

        }

        $hopAnchoTotal = max($hopAnchoTotal);
        $hopLargoTotal = max($hopLargoTotal);

        $package['width'] = $hopAnchoTotal;
        $package['length'] = $hopLargoTotal;
        $package['height'] = $hopAltoTotal;
        $package['value'] = $totalPrice;
        $package['weight'] = $weightTotal;

        return $package;
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getStorename($storeId = null)
    {
        return $this->getConfigValue('trans_email/ident_sales/name', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getStoreEmail($storeId = null)
    {
        return $this->getConfigValue('trans_email/ident_sales/email', $storeId);
    }
    /**
     * @param int|null $storeId
     * @return string
     */
    public function getStoreCountry($storeId = null)
    {
        return $this->getConfigValue('general/country/default', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isAmastyOscEnabled($storeId = null)
    {
        $amastyModules = [
            'Amasty_Checkout',
            'Amasty_CheckoutCore',
        ];

        $amastyPresentAndEnabled = false;
        foreach ($amastyModules as $moduleName) {
            if ($this->moduleManager->isEnabled($moduleName)) {
                $amastyPresentAndEnabled = true;
                break;
            }
        }

        if (!$amastyPresentAndEnabled) {
            return false;
        }

        return (bool)$this->getConfigValue('amasty_checkout/general/enabled', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function idVatShow($storeId = null)
    {
        return (bool)$this->getConfigValue('customer/address/taxvat_show', $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function IsVAtShowToFrontend($storeId = null)
    {
        return (bool)$this->getConfigValue('customer/create_account/vat_frontend_visibility', $storeId);
    }
}

