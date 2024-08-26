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
use Psr\Log\LoggerInterface;

/**
 * Class Data
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
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
        ShippingData $shippingHelper
    ) {
        $this->_scopeConfig             = $scopeConfig;
        $this->_storeManagerInterface   = $storeManagerInterface;
        $this->_region                  = $region;
        $this->_checkoutSession         = $checkoutSession;
        $this->fieldsetConfig           = $fieldsetConfig;
        $this->logger                   = $logger;
        $this->quoteRepository          = $cartRepository;
        $this->_shippingData            = $shippingHelper;
    }

    /**
     * @return string
     */
    public function getClientId()
    {
        return $this->_scopeConfig->getValue('shipping/hop/client_id', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getClientSecret()
    {
        return $this->_scopeConfig->getValue('shipping/hop/client_secret', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getApiKey()
    {
        return $this->_scopeConfig->getValue('shipping/hop/api_key', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->_scopeConfig->getValue('shipping/hop/email', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getPassword()
    {
        return $this->_scopeConfig->getValue('shipping/hop/password', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function useCustomerTaxvat()
    {
        return (bool)$this->_scopeConfig->getValue('shipping/hop/use_customer_taxvat', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getCustomerDocumentAttribute()
    {
        return $this->_scopeConfig->getValue('shipping/hop/customer_document_attribute', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function isActive()
    {
        return (bool)$this->_scopeConfig->getValue('carriers/hop/active', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @param $path
     * @param $params
     * @return string
     * @throws NoSuchEntityException
     */
    public function getStoreUrl($path,$params)
    {
        return $this->_storeManagerInterface->getStore()->getUrl($path,$params);
    }

    /**
     * @return float
     */
    public function getMaxWeight()
    {
        return (float)$this->_scopeConfig->getValue("carriers/hop/max_package_weight", ScopeInterface::SCOPE_STORE);
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
     * @return bool
     */
    public function getProductivo()
    {
        return (boolean) $this->_scopeConfig->getValue('shipping/hop/modo_productivo', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getOriginZipcode()
    {
        return $this->_scopeConfig->getValue('shipping/hop/origin_zipcode', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getSellerCode()
    {
        return $this->_scopeConfig->getValue('shipping/hop/seller_code', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return false|string[]
     */
    public function getStatusOrderAllowed()
    {
        $statusOrderAllowed = $this->_scopeConfig->getValue('shipping/hop/status_allowed', ScopeInterface::SCOPE_STORE);
        $statusOrderAllowed = explode(',', $statusOrderAllowed);
        return $statusOrderAllowed;
    }

    /**
     * @return string
     */
    public function getShippingType()
    {
        return $this->_scopeConfig->getValue('shipping/hop/shipping_type', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getLabelType()
    {
        return $this->_scopeConfig->getValue('shipping/hop/type_label', ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getDaysOffset()
    {
        return $this->_scopeConfig->getValue('shipping/hop/days_offset',ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return int
     */
    public function getSizeCategory()
    {
        return $this->_scopeConfig->getValue('shipping/hop/size_category',ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return string
     */
    public function getStorageCode()
    {
        return $this->_scopeConfig->getValue('shipping/hop/storage_code',ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return bool
     */
    public function getValidateClientId()
    {
        return $this->_scopeConfig->getValue('shipping/hop/validate_client_id',ScopeInterface::SCOPE_STORE);
    }

    /**
     * @param $mensaje String
     * @param $archivo String
     */
    public function log($mensaje, $isError = false, $isArray = false)
    {
        if($isError){
            $file = 'error_hop_'.date('m_Y').'.log';
        }else{
            $file = 'hop'.date('m_Y').'.log';
        }

        $writer = new \Zend_Log_Writer_Stream(BP . '/var/log/'.$file);
        $logger = new \Zend_Log();
        $logger->addWriter($writer);

        if($isArray){
            $logger->info(print_r($mensaje, true));
        }else{
            $logger->info($mensaje);
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
     * @return array
     */
    public function getPackageData($order)
    {
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
                    ->getAttributeRawValue($_product->getId(),'hop_alto',$_product->getStoreId()) * $_item->getQtyOrdered();
            $hopAltoTotal += $hopAlto;

            $hopLargo = (int) $_product->getResource()
                    ->getAttributeRawValue($_product->getId(),'hop_largo',$_product->getStoreId()) * $_item->getQtyOrdered();
            $hopLargoTotal[] = $hopLargo;

            $hopAncho = (int) $_product->getResource()
                    ->getAttributeRawValue($_product->getId(),'hop_ancho',$_product->getStoreId()) * $_item->getQtyOrdered();
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
     * @return mixed
     */
    public function getStorename()
    {
        return $this->_scopeConfig->getValue(
            'trans_email/ident_sales/name',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @return mixed
     */
    public function getStoreEmail()
    {
        return $this->_scopeConfig->getValue(
            'trans_email/ident_sales/email',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}

