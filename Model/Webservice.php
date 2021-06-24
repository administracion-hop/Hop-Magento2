<?php

namespace Improntus\Hop\Model;

use Improntus\Hop\Helper\Data as HelperHop;

/**
 * Class Webservice
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Model
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
     * @var string
     */
    private $_refreshToken;


    /**
     * Webservice constructor.
     * @param HelperHop $helperHop
     */
    public function __construct(
        HelperHop $helperHop
    )
    {
        $this->_helper = $helperHop;

        $this->_clientId = $helperHop->getClientId();
        $this->_clientSecret = $helperHop->getClientSecret();
        $this->_email = $helperHop->getEmail();
        $this->_password = $helperHop->getPassword();

        $this->login();
    }

    /**
     * @return bool
     */
    public function login()
    {
        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';

        $curl = curl_init();

        curl_setopt_array($curl,
        [
            CURLOPT_URL => "https://".$entorno."api.hopenvios.com.ar/api/v1/login?client_id={$this->_clientId}&client_secret={$this->_clientSecret}&email={$this->_email}&password={$this->_password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
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
        $this->_refreshToken = isset($response->refresh_token) ? $response->refresh_token : null;

        return true;
    }

    /**
     * @param integer $zipCode
     * @return bool|mixed
     */
    public function getPickupPoints($zipCode)
    {
        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';

        $curl = curl_init();
        $query = array("allow_drop_off" => 1, "allow_deliveries" => 1, "allow_drop_shipping" => 1, "zipcode" => $zipCode);
        $data = http_build_query($query);
        curl_setopt_array($curl,
            [
                CURLOPT_URL => "https://".$entorno."api.hopenvios.com.ar/api/v1/pickup_points"."?". $data,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$this->_accessToken}",
                    "Content-Type: application/json"
                ],
            ]);

        $response = curl_exec($curl);

        if(curl_error($curl))
        {
            $error = 'Se produjo un error al solicitar cotización: '. curl_error($curl);
            $this->_helper->log($error ,true);

            return false;
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
    public function estimatePrice($originZipCode,$destinyZipCode,$shippingType = 'E',$package = [], $sellerCode,$hopPointId)
    {
        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';

        $curl = curl_init();
        $width = $package['width'];
        $length = $package['length'];
        $height = $package['height'];
        $value = $package['value'];

        $url = "https://".$entorno."api.hopenvios.com.ar/api/v1/pricing/estimate";
        $url .= "?origin_zipcode=$originZipCode";
        $url .= "&destiny_zipcode=$destinyZipCode";
        $url .= "&shipping_type=$shippingType";
        $url .= "&package[value]=$value&seller_code=$sellerCode&package[width]=$width&package[length]=$length&package[height]=$height&pickup_point_id=$hopPointId";

        curl_setopt_array($curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$this->_accessToken}",
                    "Content-Type: application/json"
                ],
            ]);

        $response = curl_exec($curl);
        $responseObject = json_decode($response);

        if(curl_error($curl))
        {
            $error = 'Se produjo un error al solicitar cotización: '. curl_error($curl);
            $this->_helper->log('Error:', true);
            $this->_helper->log($error, true);

            return false;
        }

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
        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';

        $sellerCode = $this->_helper->getSellerCode();
        $shippingType = $this->_helper->getShippingType();
        $labelType = $this->_helper->getLabelType();
        $daysOffset = $this->_helper->getDaysOffset();
        $validateClientId = $this->_helper->getValidateClientId();
        $sizeCategory = $this->_helper->getSizeCategory();
        $storageCode = $this->_helper->getStorageCode();
        $packageData = $this->_helper->getPackageData($order);

        $hopData = $order->getHopData();
        $hopData = json_decode($hopData);
        $pickupPointId = isset($hopData->hopPointId) ? $hopData->hopPointId : 0;

        $billingAddress = $order->getBillingAddress();

        $params = [];
        $params['shipping_type'] = $shippingType;
        $params['reference_1'] = $order->getIncrementId();
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
            $paramClient['id_number'] = $order->getCustomerTaxvat();
        }
        else{
            $paramClient['id_number'] = $order->getData($this->_helper->getCustomerDocumentAttribute());
        }

        $paramClient['telephone'] = ($billingAddress->getTelephone()) ? $billingAddress->getTelephone() : '';
        $params['client'] = $paramClient;

        $paramPackage = [];
        $paramPackage['size_category'] = $sizeCategory;
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

        $curl = curl_init();
        $url = "https://".$entorno."api.hopenvios.com.ar/api/v1/shipping";
        curl_setopt_array($curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => $postFields,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$this->_accessToken}",
                    "Content-Type: application/json"
                ],
            ]);

        $responseJson = curl_exec($curl);
        $responseObject = json_decode($responseJson);

        $this->_helper->log('Request POST: '.$url);
        $this->_helper->log($responseObject, false, true);

        if(curl_error($curl))
        {
            $error = 'Se produjo un error al generar el shipping: '. curl_error($curl);
            $this->_helper->log('Error:', true);
            $this->_helper->log($error, true);

            return false;
        }

        if(isset($responseObject->tracking_nro)){
            return $responseJson;
        }else{
            return false;
        }
    }
}