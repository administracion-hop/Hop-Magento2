<?php

namespace Hop\Envios\Model;

use Hop\Envios\Helper\Data as HelperHop;
use Hop\Envios\Model\ResourceModel\Point\CollectionFactory as PointCollectionFactory;
use Hop\Envios\Model\PointFactory;
use Hop\Envios\Model\ResourceModel\Point;
use Magento\Framework\Message\ManagerInterface;
use Hop\Envios\Model\ResourceModel\Token\CollectionFactory as TokenCollectionFactory;
use Hop\Envios\Model\TokenFactory;
use Hop\Envios\Model\ResourceModel\Token as TokenResourceModel;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Class Webservice
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model
 */
class Webservice
{
    /**
     * @var string
     */
    protected $_clientId;

    /**
     * @var string
     */
    protected $_clientSecret;

    /**
     * @var string
     */
    protected $_email;

    /**
     * @var string
     */
    protected $_password;

    /**
     * @var HelperHop
     */
    protected $_helper;

    /**
     * @var string
     */
    private $_tokenType;

    /**
     * @var string
     */
    private $_accessToken;

    /**
     * @var PointCollectionFactory
     */
    private $pointCollectionFactory;

    /**
     * @var PointFactory
     */
    private $pointFactory;

    /**
     * @var Point
     */
    private $pointResource;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @var TokenCollectionFactory
     */
    protected $tokenCollectionFactory;

    /**
     * @var TokenFactory
     */
    protected $tokenFactory;

    /**
     * @var TokenResourceModel
     */
    protected $tokenResourceModel;


    /**
     * Webservice constructor.
     * @param HelperHop $helperHop
     * @param PointCollectionFactory $pointCollectionFactory
     * @param PointFactory $pointFactory
     * @param Point $pointResource
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        HelperHop $helperHop,
        PointCollectionFactory $pointCollectionFactory,
        PointFactory $pointFactory,
        Point $pointResource,
        ManagerInterface $messageManager,
        TokenCollectionFactory $tokenCollectionFactory,
        TokenFactory $tokenFactory,
        TokenResourceModel $tokenResourceModel
    )
    {
        $this->_helper = $helperHop;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->pointFactory = $pointFactory;
        $this->pointResource = $pointResource;
        $this->messageManager = $messageManager;
        $this->tokenCollectionFactory = $tokenCollectionFactory;
        $this->tokenFactory = $tokenFactory;
        $this->tokenResourceModel = $tokenResourceModel;

        $this->_clientId = $helperHop->getClientId();
        $this->_clientSecret = $helperHop->getClientSecret();
        $this->_email = $helperHop->getEmail();
        $this->_password = $helperHop->getPassword();

        $this->login();
    }

    /**
     * Performs an HTTP request using cURL with automatic authentication retry
     *
     * This method sends HTTP requests to an API endpoint with support for different HTTP verbs,
     * automatic token refresh on 401 (Unauthorized) errors, and logging.
     *
     * @param string $verb The HTTP method to use (e.g., 'GET', 'POST', 'PUT', 'DELETE')
     * @param string $path The API endpoint path (without the full URL)
     * @param mixed $postFields Optional data to be sent with the request (typically for POST/PUT)
     *
     * @return string|false The API response body on success, or false on failure
     *
     * @throws Exception Potential exceptions from cURL operations
     */
    protected function curl($verb, $path, $postFields = false)
    {
        $retry = false;
        do {
            $curl = curl_init();
            $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';
            $url = "https://" . $entorno . $path;
            $this->_helper->log('API URL: ' . $url);
            $curlData = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_CUSTOMREQUEST => $verb,
                CURLINFO_HEADER_OUT => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$this->_accessToken}",
                    "Content-Type: application/json"
                ],
            ];

            if ($postFields){
                $curlData[CURLOPT_POSTFIELDS] = $postFields;
            }
            curl_setopt_array($curl, $curlData);

            $response = curl_exec($curl);

            if (!$retry && curl_getinfo($curl, CURLINFO_HTTP_CODE) === 401){
                $this->login(true);
                $retry = true;
            } else {
                $retry = false;
            }
        } while($retry);

        if(curl_error($curl))
        {
            $error = 'Se produjo un error: '. curl_error($curl);
            $this->_helper->log($error ,true);
            $this->messageManager->addError($error);
            $response = false;
        }

        return $response;
    }

    /**
     * @param bool $forceNewToken
     * @return bool
     */
    public function login($forceNewToken = false)
    {
        if (!$forceNewToken) {
            $lastToken = $this->getLastToken();
            if ($lastToken && $lastToken->getId()) {
                $createdAt = strtotime($lastToken->getCreatedAt());
                $expiresIn = $lastToken->getExpiresIn();
                if (time() < ($createdAt + $expiresIn)){
                    $this->_tokenType = $lastToken->getTokenType();
                    $this->_accessToken = $lastToken->getAccessToken();
                    return true;
                }
            }
        }

        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';

        $curl = curl_init();

        curl_setopt_array($curl,
        [
            CURLOPT_URL => "https://".$entorno."api.hopenvios.com.ar/api/v1/login?client_id={$this->_clientId}&client_secret={$this->_clientSecret}&email={$this->_email}&password={$this->_password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CUSTOMREQUEST => "POST",
        ]);

        $response = curl_exec($curl);

        if(curl_error($curl))
        {
            $error = 'Se produjo un error al solicitar cotización: '. curl_error($curl);
            $this->_helper->log($error ,true);

            return false;
        }

        $response = json_decode($response);

        $this->_tokenType = isset($response->token_type) ? $response->token_type : null;
        $this->_accessToken = isset($response->access_token) ? $response->access_token : null;

        try {
            $this->saveNewToken(
                $this->_tokenType,
                $this->_accessToken,
                isset($response->expires_in) ? $response->expires_in / 1000 : 0
            );
        } catch (LocalizedException  $e){
            $this->_helper->log('Error saving token: ' . $e->getMessage(), true);
        }
        return true;
    }

    /**
     * Get the last added token record
     *
     * @param string|null $tokenType Optional token type to filter
     * @return Token|null
     */
    public function getLastToken($tokenType = null)
    {
        /** @var \Hop\Envios\Model\ResourceModel\Token\Collection $collection */
        $collection = $this->tokenCollectionFactory->create();
        $collection->setOrder('created_at', 'DESC');
        if ($tokenType !== null) {
            $collection->addFieldToFilter('token_type', $tokenType);
        }
        $collection->setPageSize(1);
        return $collection->getFirstItem();
    }

    /**
     * Save a new token to the database
     *
     * @param string $tokenType Type of token (e.g., 'access', 'refresh')
     * @param string $accessToken The actual token string
     * @param int $expiresIn Expiration time in seconds
     * @return \Hop\Envios\Model\Token
     * @throws LocalizedException
     */
    public function saveNewToken($tokenType, $accessToken, $expiresIn)
    {
        if (empty($tokenType)) {
            throw new LocalizedException(
                new Phrase('Token type cannot be empty')
            );
        }

        if (empty($accessToken)) {
            throw new LocalizedException(
                new Phrase('Access token cannot be empty')
            );
        }

        if ($expiresIn <= 0) {
            throw new LocalizedException(
                new Phrase('Expires in must be a positive number')
            );
        }

        $token = $this->tokenFactory->create();
        $token->setTokenType($tokenType);
        $token->setAccessToken($accessToken);
        $token->setExpiresIn($expiresIn);

        try {
            $this->tokenResourceModel->save($token);
            return $token;
        } catch (\Exception $e) {
            $this->_helper->log('Error saving token: ' . $e->getMessage(), true);
            throw new LocalizedException(
                new Phrase('Unable to save token: %1', [$e->getMessage()])
            );
        }
    }

    /**
     * @param integer $zipCode
     * @return bool|mixed
     */
    public function getPickupPoints($zipCode, $force_from_api = false)
    {
        if (!$force_from_api){
            $collection = $this->pointCollectionFactory->create()->addFieldToFilter('zip_code', $zipCode);
            if ($collection->getSize()) {
                $pointes = $collection->getFirstItem();
                $pointData = $pointes->getPointData();
                return json_decode($pointData);
            }
        }

        $curlRequest = "api.hopenvios.com.ar/api/v1/pickup_points";
        if($zipCode){
            $curlRequest = "api.hopenvios.com.ar/api/v1/pickup_points?allow_deliveries=1&zip_code=".$zipCode;
        }

        $response = $this->curl("GET", $curlRequest);

        if (json_decode($response)) {
            try {
                $point = $this->pointFactory->create();
                $point->setZipCode($zipCode);
                $point->setPointData(json_encode(json_decode($response)));
                if (!$force_from_api){
                    $this->pointResource->save($point);
                }
            } catch (\Exception $e) {
                $this->_helper->log($e->getMessage(), true);
            }
        }

        return json_decode($response);
    }

    /**
     * @param $originZipCode
     * @param $destinyZipCode
     * @param string $shippingType
     * @param array $package
     * @param $sellerCode
     * @param $hopPointId
     * @return false
     */
    public function estimatePrice($originZipCode,$destinyZipCode,$sellerCode,$hopPointId,$shippingType = 'E',$package = [])
    {
        $width = $package['width'];
        $length = $package['length'];
        $height = $package['height'];
        $weight = $package['weight'];
        $value = $package['value'];

        $url = "api.hopenvios.com.ar/api/v1/pricing/estimate";
        $url .= "?origin_zipcode=$originZipCode";
        $url .= "&destiny_zipcode=$destinyZipCode";
        $url .= "&shipping_type=$shippingType";
        $url .= "&package[value]=$value&weight=$weight&seller_code=$sellerCode&package[width]=$width&package[length]=$length&package[height]=$height";
        $sizeCategory = $this->_helper->getSizeCategory();
        if ($sizeCategory){
            $url .= "&package[size_category]=$sizeCategory";
        }
        if ($hopPointId){
            $url .= "&pickup_point_id=$hopPointId";
        }

        $response = $this->curl("GET", $url);
        $responseObject = json_decode($response);

        if(isset($responseObject->data->amount)){
            return $responseObject->data->amount;
        }elseif(isset($responseObject->errors)){
            return false;
        }else{
            return false;
        }
    }

    /**
     * @param $order
     * @return bool|string
     */
    public function createShipping($order)
    {
        $sellerCode = $this->_helper->getSellerCode();
        $shippingType = $this->_helper->getShippingType();
        $labelType = $this->_helper->getLabelType();
        $daysOffset = $this->_helper->getDaysOffset();
        $validateClientId = $this->_helper->getValidateClientId();
        $sizeCategory = $this->_helper->getSizeCategory();
        $storageCode = $this->_helper->getStorageCode();
        $packageData = $this->_helper->getPackageData($order);

        $hopData = $order->getHopData();
        if (!$hopData) {
            $this->_helper->log('No Hop Data', true);
            return false;
        }
        $hopData = json_decode($hopData);
        $pickupPointId = isset($hopData->hopPointId) ? $hopData->hopPointId : 0;

        $billingAddress = $order->getBillingAddress();

        $params = [];
        $params['shipping_type'] = $shippingType;
        $params['reference_id'] = $sellerCode.'-'.$order->getIncrementId();
        $params['reference_2'] = '';
        $params['reference_3'] = '';
        $params['label_type'] = $labelType;
        $params['seller_code'] = $sellerCode;
        $params['storage_code'] = $storageCode;
        $params['days_offset'] = $daysOffset;
        $params['validate_client_id'] = $validateClientId;
        $params['pickup_point_id'] = $pickupPointId;

        $paramClient = [];
        $paramClient['name'] = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $paramClient['email'] = $order->getCustomerEmail();
        $paramClient['id_type'] = 'D.N.I';

        if($this->_helper->useCustomerTaxvat()){
            $paramClient['id_number'] = $billingAddress->getVatId();
        }
        else{
            $paramClient['id_number'] = $order->getData($this->_helper->getCustomerDocumentAttribute());
        }

        $paramClient['telephone'] = ($billingAddress->getTelephone()) ? $billingAddress->getTelephone() : '';
        $params['client'] = $paramClient;

        $paramPackage = [];
        if ($sizeCategory){
            $paramPackage['size_category'] = $sizeCategory;
        }
        $paramPackage['width'] = $packageData['width'];
        $paramPackage['length'] = $packageData['length'];
        $paramPackage['height'] = $packageData['height'];
        $paramPackage['value'] = $packageData['value'];
        $paramPackage['weight'] = $packageData['weight'];
        $params['package'] = $paramPackage;

        $paramSender = [];
        $paramSender['name'] = $this->_helper->getStorename();
        $paramSender['id_number'] = '';
        $paramSender['phone'] = '';
        $paramSender['mail'] = $this->_helper->getStoreEmail();
        $params['sender'] = $paramSender;

        $postFields = json_encode($params);

        $this->_helper->log($params, false, true);

        $url = "api.hopenvios.com.ar/api/v1/shipping";

        $responseJson = $this->curl('POST', $url, $postFields);
        $responseObject = json_decode($responseJson);

        $this->_helper->log('Request POST: '.$url);
        $this->_helper->log($responseObject, false, true);

        if(isset($responseObject->tracking_nro)){
            return $responseJson;
        }else{
            $error = __('Hubo un error al enviar su pedido a Hop: ');
            if (gettype($responseObject) == 'object'){
                $keys = get_object_vars($responseObject);
                foreach($keys as $key){
                    if (is_array($key)){
                        foreach($key as $message){
                            if (is_string($message)){
                                $error .= $message . ". ";
                            }
                        }
                    }
                }
            } else if (is_string($responseObject)){
                $error .= $responseObject . ".";
            }
            $this->_helper->log('Error:', true);
            $this->_helper->log($error, true);
            $this->messageManager->addError($error);
            return array(
                'error' => $error
            );
        }
    }
}
