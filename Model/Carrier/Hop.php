<?php

namespace Improntus\Hop\Model\Carrier;

use Exception;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Directory\Helper\Data;
use Magento\Directory\Model\Country;
use Magento\Directory\Model\CountryFactory;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\State;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Shipping\Model\Simplexml\ElementFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use Psr\Log\LoggerInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Improntus\Hop\Helper\Data as HopHelper;
use Improntus\Hop\Model\Webservice;
use Magento\Framework\Xml\Security;
use Improntus\Hop\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Improntus\Hop\Model\ResourceModel\Point;
use Improntus\Hop\Model\PointFactory;
use Magento\Checkout\Model\Session;

/**
 * Class Hop
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Model\Carrier
 */
class Hop extends AbstractCarrierOnline implements CarrierInterface
{
    const CARRIER_CODE = 'hop';

    /**
     * @var string
     */
    protected $_code = self::CARRIER_CODE;

    /**
     * @var
     */
    protected $_webservice;

    /**
     * @var HopHelper
     */
    protected $_helper;

    /**
     * @var RateRequest
     */
    protected $_rateRequest;

    /**
     * @var ResultFactory
     */
    protected $_rateResultFactory;

    /**
     * @var MethodFactory
     */
    protected $_rateMethodFactory;

    /**
     * @var RequestInterface
     */
    protected $_request;

    /**
     * Rate result data
     *
     * @var Result
     */
    protected $_result;

    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var CartRepositoryInterface
     */
    protected $_quoteRepository;

    /**
     * @var PointCollectionFactory
     */
    protected $pointCollectionFactory;

    /**
     * @var PointFactory
     */
    protected $pointFactory;

    /**
     * @var Point
     */
    protected $pointResource;

    /**
     * @var State
     */
    protected $appState;

    /**
     * Hop constructor.
     * @param ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param Security $xmlSecurity
     * @param ElementFactory $xmlElFactory
     * @param ResultFactory $rateFactory
     * @param MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory
     * @param StatusFactory $trackStatusFactory
     * @param RegionFactory $regionFactory
     * @param CountryFactory $countryFactory
     * @param CurrencyFactory $currencyFactory
     * @param Data $directoryData
     * @param StockRegistryInterface $stockRegistry
     * @param RequestInterface $request
     * @param Webservice $webservice
     * @param HopHelper $hopHelper
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param State $appState
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        Security $xmlSecurity,
        ElementFactory $xmlElFactory,
        ResultFactory $rateFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        StatusFactory $trackStatusFactory,
        RegionFactory $regionFactory,
        CountryFactory $countryFactory,
        CurrencyFactory $currencyFactory,
        Data $directoryData,
        StockRegistryInterface $stockRegistry,
        RequestInterface $request,
        Webservice $webservice,
        HopHelper $hopHelper,
        Session $checkoutSession,
        CartRepositoryInterface $quoteRepository,
        PointCollectionFactory $pointCollectionFactory,
        PointFactory $pointFactory,
        Point $pointResource,
        State $appState,
        array $data = []
    )
    {
        $this->_rateResultFactory = $rateFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_helper            = $hopHelper;
        $this->_webservice        = $webservice;
        $this->_request           = $request;
        $this->_checkoutSession   = $checkoutSession;
        $this->_quoteRepository   = $quoteRepository;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->pointFactory = $pointFactory;
        $this->pointResource = $pointResource;
        $this->trackStatusFactory = $trackStatusFactory;
        $this->appState = $appState;
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );
    }
    /**
     * @return bool
     */
    public function isTrackingAvailable()
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isCityRequired()
    {
        return true;
    }

    /**
     * @param null $countryId
     * @return bool
     */
    public function isZipCodeRequired($countryId = null)
    {
        if ($countryId != null) {
            return !$this->_directoryData->isZipCodeOptional($countryId);
        }
        return true;
    }

    /**
     * Is state province required
     *
     * @return bool
     */
    public function isStateProvinceRequired()
    {
        return true;
    }

    /**
     * Indicates whether the current area is admin area
     *
     * @return bool
     * @throws LocalizedException
     */
    protected function isAdmin(): bool
    {
        if ($this->appState->getAreaCode() === FrontNameResolver::AREA_CODE) {
            return true;
        }
        return false;
    }

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return ['hop' => $this->getConfigData('title')];
    }

    /**
     * @param RateRequest $request
     * @return bool|Result
     * @throws LocalizedException
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active'))
        {
            return false;
        }

        $helper = $this->_helper;

        $result = $this->_rateResultFactory->create();
        $method = $this->_rateMethodFactory->create();


        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('description'));

        $webservice = $this->_webservice;

        $totalPrice = 0;

        $currentZipCode = (int)$request->getDestPostcode();
        $zipCode = (int)$request->getDestPostcode();
        $quote = $this->_checkoutSession->getQuote();
        // $quoteId = $quote->getId(); // get the current quote id
        // $quoteFromDb = $this->_quoteRepository->get($quoteId); // load the quote from the database
        // $shippingAddressFromDb = $quoteFromDb->getShippingAddress();
        // $quotePostcode = (int)$this->_checkoutSession->getCustomerZipcode(); // get the zipcode stored in the session

        $isAdmin = $this->isAdmin();


        $hopData = $this->_checkoutSession->getHopData();

        if($zipCode && ($isAdmin || (is_array($hopData) && count($hopData))))
        {
            $hopAltoTotal = 0;
            $hopLargoTotal = [];
            $hopAnchoTotal = [];
            foreach($request->getAllItems() as $_item)
            {
                if($_item->getProductType() == 'configurable')
                    continue;

                $_product = $_item->getProduct();

                if($_item->getParentItem())
                    $_item = $_item->getParentItem();

                $hopAlto = (int) $_product->getResource()
                        ->getAttributeRawValue($_product->getId(),'hop_alto',$_product->getStoreId()) * $_item->getQty();
                $hopAltoTotal += $hopAlto;

                $hopLargo = (int) $_product->getResource()
                        ->getAttributeRawValue($_product->getId(),'hop_largo',$_product->getStoreId()) * $_item->getQty();
                $hopLargoTotal[] = $hopLargo;

                $hopAncho = (int) $_product->getResource()
                        ->getAttributeRawValue($_product->getId(),'hop_ancho',$_product->getStoreId()) * $_item->getQty();
                $hopAnchoTotal[] = $hopAncho;

                $totalPrice += $_product->getFinalPrice() * $_item->getQty();
            }

            $hopAnchoTotal = max($hopAnchoTotal);
            $hopLargoTotal = max($hopLargoTotal);
            $pesoTotal  = $request->getPackageWeight(); //Peso en unidad de kg

            if($pesoTotal > (int)$helper->getMaxWeight())
            {
                $error = $this->_rateErrorFactory->create();
                $error->setCarrier($this->_code);
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage(__('Su pedido supera el peso máximo permitido por Hop. Por favor divida su orden en más pedidos o consulte al administrador de la tienda.'));

                return $error;
            }

            if($request->getFreeShipping() || ($this->getConfigData('hop_free_shipping') && $totalPrice >= $this->getConfigData('hop_free_shipping')))
            {
                $method->setPrice(0);
                $method->setCost(0);
            }
            else
            {
                $originZipCode = $this->_helper->getOriginZipcode();
                $destZipCode = $isAdmin ? $zipCode : $hopData['hopPointPostcode'];
                $hopPointId = $isAdmin ? '' : $hopData['hopPointId'];
                $sellerCode = $helper->getSellerCode();
                $costoEnvio = $webservice->estimatePrice(
                    $originZipCode,
                    $destZipCode,
                    $sellerCode,
                    $hopPointId,
                    'E',
                    [
                        'width' => $hopAnchoTotal,
                        'length' => $hopLargoTotal,
                        'height' => $hopAltoTotal,
                        'weight' => $pesoTotal * 1000,
                        'value' => (int)$totalPrice
                    ]
                );
                $dataForLog = array(
                    'origin_zip_code' => $originZipCode,
                    'hop_zip_code' => $destZipCode,
                    'seller_code' => $sellerCode,
                    'hop_point_id' => $hopPointId,
                    'product_data' => [
                        'width' => $hopAnchoTotal,
                        'length' => $hopLargoTotal,
                        'height' => $hopAltoTotal,
                        'weight' => $pesoTotal,
                        'value' => (int)$totalPrice
                    ],
                    'hop_cost' => $costoEnvio
                );
                $helper->log("COTIZACIÓN");
                $helper->log($dataForLog, false, true);
                if (!$costoEnvio) {
                    return $result;
                }
                $percentageRate = $this->getConfigData('percentage_rate');
                $fixedValue = $this->getConfigData('fixed_value');

                if (!empty($percentageRate)) {

                    $adjustedShippingCost = ($percentageRate == 1) ? $costoEnvio : $costoEnvio * $percentageRate;

                    if (!empty($fixedValue)) {
                        if ($fixedValue >= 0)
                        {
                            $adjustedShippingCost += $fixedValue;
                        }
                        else
                        {
                            $adjustedShippingCost -= abs($fixedValue);
                        }
                    }

                } else {
                    $adjustedShippingCost = $costoEnvio;

                    if (!empty($fixedValue)) {
                       if ($fixedValue >= 0)
                        {
                            $adjustedShippingCost += $fixedValue;
                        }
                        else
                        {
                            $adjustedShippingCost -= abs($fixedValue);
                        }
                    }
                }

                $adjustedShippingCost = max(0, $adjustedShippingCost);
                $method->setPrice($adjustedShippingCost);
                $method->setCost($adjustedShippingCost);


            }
            if($method->getPrice() !== false)
            {
                if(isset($hopData['hopPointName']) && isset($hopData['hopPointAddress']))
                {
                    $method->setMethodTitle(
                        'Retirá tu pedido en: ' .
                        $hopData['hopPointReferenceName']
                        . " ({$hopData['hopPointAddress']}) " .
                        ' - Horario: '.$hopData['hopPointSchedules']
                    );

                    $quote = $this->_checkoutSession->getQuote();
                    $quote->setHopData(json_encode($hopData));
                    $quote->save();
                }

                $result->append($method);
            }
            else
            {
                $error = $this->_rateErrorFactory->create();
                $error->setCarrier($this->_code);
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage(__('No existen cotizaciones para la dirección ingresada'));

                return $error;
            }
        } else if (!$isAdmin){
            $method->setPrice(0);
            $method->setCost(0);
            $result->append($method);
            $this->_checkoutSession->setCustomerZipcode($currentZipCode);
            return $result;
        }
        return $result;
    }

    /**
     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
     *
     * @param DataObject $request
     * @return DataObject
     * @throws Exception
     */
    protected function _doShipmentRequest(DataObject $request)
    {
        $this->_prepareShipmentRequest($request);
        $xmlRequest = $this->_formShipmentRequest($request);
        $xmlResponse = $this->_getCachedQuotes($xmlRequest);

        if ($xmlResponse === null)
        {
            $url = $this->getShipConfirmUrl();

            $debugData = ['request' => $xmlRequest];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, (bool)$this->getConfigFlag('mode_xml'));
            $xmlResponse = curl_exec($ch);
            if ($xmlResponse === false)
            {
                throw new Exception(curl_error($ch));
            } else {
                $debugData['result'] = $xmlResponse;
                $this->_setCachedQuotes($xmlRequest, $xmlResponse);
            }
        }
    }

    /**
     * Processing additional validation to check if carrier applicable.
     *
     * @param DataObject $request
     * @return $this|bool|DataObject
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function proccessAdditionalValidation(DataObject $request)
    {
        return $this;
    }


    public function getTrackingInfo($trackingNumber)
    {
        $tracking = $this->trackStatusFactory->create();

        $url = 'https://hopenvios.com.ar/segui-tu-envio?c=' . $trackingNumber; // this is the tracking URL of stamps.com, replace this with your's

        $tracking->setData([
            'carrier' => $this->_code,
            'carrier_title' => $this->getConfigData('title'),
            'tracking' => $trackingNumber,
            'url' => $url,
        ]);
        return $tracking;
    }
}
