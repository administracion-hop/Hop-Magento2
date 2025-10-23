<?php

namespace Hop\Envios\Model\Carrier;

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
use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\Webservice;
use Magento\Framework\Xml\Security;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Model\ResourceModel\Point;
use Hop\Envios\Model\PointFactory;
use Magento\Checkout\Model\Session;
use Magento\Sales\Api\OrderRepositoryInterface;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\SelectedPickupPointRepository;

/**
 * Class Hop
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\Carrier
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
     * @var OrderRepositoryInterface
     */

    private $orderRepository;

    /**
     * @var HopEnviosRepository
     */

    protected $hopEnviosRepository;

    /**
     * @var SelectedPickupPointRepository
     */
    protected $selectedPickupPointRepository;



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
     * @param HopEnviosRepository $hopEnviosRepository
     * @param State $appState
     * @param SelectedPickupPointRepository $selectedPickupPointRepository
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
        OrderRepositoryInterface $orderRepository,
        HopEnviosRepository $hopEnviosRepository,
        SelectedPickupPointRepository $selectedPickupPointRepository,
        array $data = []
    ) {
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
        $this->orderRepository = $orderRepository;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->selectedPickupPointRepository = $selectedPickupPointRepository;
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
        if (!$this->getConfigFlag('active')) {
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

        $destZipCode = (int)$request->getDestPostcode();
        $quote = $this->_checkoutSession->getQuote();

        if (!$destZipCode) {
            if($helper->isAmastyOscEnabled()) { //config amasty show method even no zip code
                $result->append($method);
            }
            return $result;
        }

        $hopData = $this->_checkoutSession->getHopData();
        $showMethod = $this->getConfigData('showmethod');

        $hopAltoTotal = 0;
        $hopLargoTotal = [];
        $hopAnchoTotal = [];

        /** @var \Magento\Quote\Model\Quote\Item $_item */
        foreach ($request->getAllItems() as $_item) {
            if ($_item->getProductType() == 'configurable') {
                continue;
            }

            /** @var \Magento\Catalog\Model\Product $_product */
            $_product = $_item->getProduct();

            if ($_item->getParentItem()) {
                $_item = $_item->getParentItem();
            }

            $qty = $_item->getQty();

            $hopAltoTotal += $this->getMeasure($_product, 'alto', $qty);
            $hopLargoTotal[] = $this->getMeasure($_product, 'largo', $qty);
            $hopAnchoTotal[] = $this->getMeasure($_product, 'ancho', $qty);

            $totalPrice += $_product->getFinalPrice() * $qty;
        }

        $hopAnchoTotal = max($hopAnchoTotal);
        $hopLargoTotal = max($hopLargoTotal);
        $pesoTotal  = $request->getPackageWeight(); //Peso en unidad de kg

        if ($pesoTotal > (int)$helper->getMaxWeight()) {
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage(__('Su pedido supera el peso máximo permitido por Hop. Por favor divida su orden en más pedidos o consulte al administrador de la tienda.'));
            $this->cleanQuoteData();
            return $error;
        }
        $pointFromZipCode = $this->_webservice->getPickupPoints($destZipCode);
        if (empty($pointFromZipCode->data)) {
            $this->cleanQuoteData();
            if (!$showMethod){
                return false;
            }
            $error = $this->_rateErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('title'));
            $error->setErrorMessage(__('No existen puntos de retiro para la dirección ingresada'));
            return $error;
        }
        if ($request->getFreeShipping() || ($this->getConfigData('hop_free_shipping') && $totalPrice >= $this->getConfigData('hop_free_shipping'))) {
            $method->setPrice(0);
            $method->setCost(0);
        } else {
            $originZipCode = $this->_helper->getOriginZipcode();
            $hopPointId = !empty($hopData['hopPointId']) ? $hopData['hopPointId'] : '';
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
                $this->cleanQuoteData();
                if (!$showMethod){
                    return false;
                }
                $error = $this->_rateErrorFactory->create();
                $error->setCarrier($this->_code);
                $error->setCarrierTitle($this->getConfigData('title'));
                $error->setErrorMessage(__('No existen cotizaciones para la dirección ingresada'));
                return $error;
            }

            $percentageRate = $this->getConfigData('percentage_rate');
            $fixedValue = $this->getConfigData('fixed_value');

            if (!empty($percentageRate)) {

                $adjustedShippingCost = ($percentageRate == 1) ? $costoEnvio : $costoEnvio * $percentageRate;

                if (!empty($fixedValue)) {
                    if ($fixedValue >= 0) {
                        $adjustedShippingCost += $fixedValue;
                    } else {
                        $adjustedShippingCost -= abs($fixedValue);
                    }
                }
            } else {
                $adjustedShippingCost = $costoEnvio;

                if (!empty($fixedValue)) {
                    if ($fixedValue >= 0) {
                        $adjustedShippingCost += $fixedValue;
                    } else {
                        $adjustedShippingCost -= abs($fixedValue);
                    }
                }
            }

            $adjustedShippingCost = max(0, $adjustedShippingCost);
            $method->setPrice($adjustedShippingCost);
            $method->setCost($adjustedShippingCost);
        }

        $zipCodeFromHopData = !empty($hopData['hopPointPostcode']) ? $hopData['hopPointPostcode'] : null;
        if ($destZipCode != $zipCodeFromHopData){
            $this->cleanQuoteData();
            $hopData = null;
        }

        if (!empty($hopData['hopPointName']) && !empty($hopData['hopPointAddress']) && !empty($hopData['hopPointId'])) {
            $shippingDescription = 'Retirá tu pedido en: ' .
                    $hopData['hopPointReferenceName']
                    . " ({$hopData['hopPointAddress']}) " .
                    ' - Horario: ' . $hopData['hopPointSchedules'];
            $method->setMethodTitle($shippingDescription);
            $quote = $this->_checkoutSession->getQuote();
            if (!$quote->getId()){
                $this->_quoteRepository->save($quote);
            }
            $selectedPickupPoint = $this->selectedPickupPointRepository->getByQuoteId($quote->getId());
            if (!$selectedPickupPoint) {
                $selectedPickupPoint = $this->selectedPickupPointRepository->create();
                $selectedPickupPoint->setQuoteId($quote->getId());
            }
            $pickupPointId = $hopData['hopPointId'];
            $selectedPickupPoint->setPickupPointId($pickupPointId);
            $selectedPickupPoint->setOriginalPickupPointId($pickupPointId);
            $selectedPickupPoint->setOriginalShippingDescription($shippingDescription);
            $this->selectedPickupPointRepository->save($selectedPickupPoint);
        }


        $result->append($method);

        return $result;
    }

    /**
     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
     *
     * @param DataObject $request
     * @return DataObject
     * @throws Exception
     */
    public function _doShipmentRequest(DataObject $request)
    {
        $this->_prepareShipmentRequest($request);
        $shipment = $request->getData('order_shipment');

        if (!$shipment || !$shipment->getOrderId()) {
            $this->_helper->log(__('Error: No se encontró un envío válido en la solicitud.'), true);
            return null;
        }

        $orderId = $shipment->getOrderId();
        $hopEnvio = $this->hopEnviosRepository->getByOrderId($orderId);

        if (empty($hopEnvio->getInfoHop()) || !is_string($hopEnvio->getInfoHop())) {
            $this->_helper->log(__('Error: El campo info_hop está vacío, no es un string válido, o no existe.'), true);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Error: Respuesta Invalidad, No existen los datos.')
            );
        }

        $infoHop = json_decode($hopEnvio->getInfoHop(), true);
        if ($infoHop === null || !is_array($infoHop)) {
            $this->_helper->log(__('Error: JSON inválido en info_hop.'), true);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Error: Respuesta Invalida, Formato invalido')
            );
        }

        if (empty($infoHop['tracking_nro']) || empty($infoHop['label_url'])) {
            $this->_helper->log(__('Error: Los valores tracking_nro o label_url están vacíos o no existen.'), true);
            return null;
        }

        $trackingNumber = $infoHop['tracking_nro'];
        $labelUrl = $infoHop['label_url'];
        try {
            $result = new \Magento\Framework\DataObject();
            $result->setTrackingNumber($trackingNumber);
            $result->setShippingLabelContent($labelUrl);
            return $result;
        } catch (\Exception $e) {
            $this->_helper->log('Error: ' . $e->getMessage(), true);
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Error: ' . $e->getMessage())
            );
        }
        return null;
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

    /**
     * @param \Magento\Catalog\Model\Product $product
     * @param string $measure_code
     * @param int $qty
     * @return int
     */
    protected function getMeasure($product, $measureCode, $qty)
    {
        $attributeCode = $this->_helper->getMeasureCode($measureCode);
        return (int) $product->getResource()->getAttributeRawValue(
            $product->getId(),
            $attributeCode,
            $product->getStoreId()
        ) * $qty;
    }

    /**
     * Clean quote data
     */
    protected function cleanQuoteData(){
        $quote = $this->_checkoutSession->getQuote();
        if (!$quote->getId()){
            $this->_quoteRepository->save($quote);
        }
        $this->selectedPickupPointRepository->deleteByQuoteId($quote->getId());
    }
}
