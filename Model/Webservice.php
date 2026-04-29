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
use Hop\Envios\Model\OrderPickupPointRepository;
use Magento\Framework\HTTP\Client\CurlFactory;

/**
 * Class Webservice
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
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
     * @var OrderPickupPointRepository
     */
    protected $orderPickupPointRepository;

    /**
     * @var string
     */
    protected $pickupPointsApiVersion;

    /**
     * @var CurlFactory
     */
    private $httpClientFactory;

    /**
     * Webservice constructor.
     * @param HelperHop $helperHop
     * @param PointCollectionFactory $pointCollectionFactory
     * @param PointFactory $pointFactory
     * @param Point $pointResource
     * @param ManagerInterface $messageManager
     * @param TokenCollectionFactory $tokenCollectionFactory
     * @param TokenFactory $tokenFactory
     * @param TokenResourceModel $tokenResourceModel
     * @param OrderPickupPointRepository $orderPickupPointRepository
     * @param CurlFactory $httpClientFactory
     */
    public function __construct(
        HelperHop $helperHop,
        PointCollectionFactory $pointCollectionFactory,
        PointFactory $pointFactory,
        Point $pointResource,
        ManagerInterface $messageManager,
        TokenCollectionFactory $tokenCollectionFactory,
        TokenFactory $tokenFactory,
        TokenResourceModel $tokenResourceModel,
        OrderPickupPointRepository $orderPickupPointRepository,
        CurlFactory $httpClientFactory
    ) {
        $this->_helper = $helperHop;
        $this->pointCollectionFactory = $pointCollectionFactory;
        $this->pointFactory = $pointFactory;
        $this->pointResource = $pointResource;
        $this->messageManager = $messageManager;
        $this->tokenCollectionFactory = $tokenCollectionFactory;
        $this->tokenFactory = $tokenFactory;
        $this->tokenResourceModel = $tokenResourceModel;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
        $this->httpClientFactory = $httpClientFactory;

        $this->_clientId = $helperHop->getClientId();
        $this->_clientSecret = $helperHop->getClientSecret();
        $this->_email = $helperHop->getEmail();
        $this->_password = $helperHop->getPassword();
        $this->pickupPointsApiVersion = $helperHop->getPickupPointsApiVersion();

        $this->login();
    }

    /**
     * Performs an HTTP request using Magento's HTTP client with automatic authentication retry.
     *
     * @param string $verb HTTP method ('GET' or 'POST')
     * @param string $path API endpoint path (without base URL)
     * @param array $queryParams Query string parameters
     * @param string|false $postFields JSON body for POST requests
     * @return string|false Response body on success, false on failure
     */
    protected function curl($verb, $path, $queryParams = [], $postFields = false)
    {
        $retry = false;
        $response = false;

        do {
            $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';
            $url = 'https://' . $entorno . $path;
            if ($queryParams) {
                $url .= '?' . http_build_query($queryParams);
            }
            $this->_helper->log('API URL: ' . $url);

            try {
                $client = $this->httpClientFactory->create();
                $client->addHeader('Authorization', 'Bearer ' . $this->_accessToken);
                $client->addHeader('Content-Type', 'application/json');

                if (strtoupper($verb) === 'POST') {
                    $client->post($url, $postFields ?: '');
                } else {
                    /**
                     * Magento's HTTP client does not have a built-in method for sending a body with GET requests, and it's generally not recommended to do so.
                     * However, if the API requires it, we can set the POSTFIELDS option directly on the cURL handle.
                     * This is a workaround and should be used with caution, as it may not be supported by all servers or may cause unexpected behavior.
                     */
                    if ($postFields) {
                        $client->setOption(CURLOPT_POSTFIELDS, $postFields);
                    }
                    $client->get($url);
                }

                if (!$retry && $client->getStatus() === 401) {
                    $this->login(true);
                    $retry = true;
                    continue;
                }

                $retry = false;
                $response = $client->getBody();
            } catch (\Exception $e) {
                $error = 'Se produjo un error: ' . $e->getMessage();
                $this->_helper->log($error, true);
                $this->messageManager->addErrorMessage($error);
                return false;
            }
        } while ($retry);

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
                if (time() < ($createdAt + $expiresIn)) {
                    $this->_tokenType = $lastToken->getTokenType();
                    $this->_accessToken = $lastToken->getAccessToken();
                    return true;
                }
            }
        }

        $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';
        $url = 'https://' . $entorno . 'api.hopenvios.com.ar/api/v1/login?' . http_build_query([
            'client_id'     => $this->_clientId,
            'client_secret' => $this->_clientSecret,
            'email'         => $this->_email,
            'password'      => $this->_password,
        ]);

        try {
            $client = $this->httpClientFactory->create();
            $client->post($url, '');
            $response = json_decode($client->getBody(), true);
        } catch (\Exception $e) {
            $error = 'Se produjo un error al solicitar cotización: ' . $e->getMessage();
            $this->_helper->log($error, true);
            return false;
        }

        if (!empty($response['errors'])) {
            $error = __('Error al iniciar sesión en Hop: ');
            try {
                $error .= $this->get_login_error_messagges($response['errors']);
            } catch (\Exception $e) {
                $error .= __('No se pudo procesar el mensaje de error: ') . $e->getMessage();
            }
            $this->_helper->log($error, true);
            return false;
        }
        $this->_tokenType = isset($response['token_type']) ? $response['token_type'] : null;
        $this->_accessToken = isset($response['access_token']) ? $response['access_token'] : null;

        try {
            $this->saveNewToken(
                $this->_tokenType,
                $this->_accessToken,
                isset($response['expires_in']) ? $response['expires_in'] / 1000 : 0
            );
        } catch (LocalizedException  $e) {
            $this->_helper->log('Error saving token: ' . $e->getMessage(), true);
        }
        return true;
    }

    /**
     * @param mixed $errors
     * @return string
     */
    protected function get_login_error_messagges($errors)
    {
        $error_list = array();
        if (is_array($errors)) {
            foreach ($errors as $err) {
                if (!empty($err['detail']) && is_string($err['detail'])) {
                    $error_list[] = $err['detail'];
                    continue;
                }
                if (!empty($err['details']) && is_array($err['details'])) {
                    foreach ($err['details'] as $detail) {
                        if (empty($detail)) {
                            continue;
                        }
                        if (is_string($detail)) {
                            $error_list[] = $detail;
                            continue;
                        }
                        if (!is_array($detail)) {
                            continue;
                        }
                        foreach ($detail as $messages) {
                            if (empty($messages)) {
                                continue;
                            }
                            if (is_string($messages)) {
                                $error_list[] = $messages;
                                continue;
                            }
                            if (!is_array($messages)) {
                                continue;
                            }
                            foreach ($messages as $message) {
                                if (is_string($message)) {
                                    $error_list[] = $message;
                                }
                            }
                        }
                    }
                }
            }
        } elseif (is_string($errors)) {
            $error_list[] = $errors;
        }
        return implode(" - ", $error_list);
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
     * Check if the seller is active
     * @return bool
     */
    public function isSellerActive()
    {
        $curlRequest = "api.hopenvios.com.ar/api/v1/sellers/" . $this->_helper->getSellerCode();
        $response = $this->curl("GET", $curlRequest);
        if ($response === false) {
            $this->_helper->log(__('Failed to check seller status: API request failed'), true);
            return false;
        }
        $decodedResponse = json_decode($response, true);
        if (empty($decodedResponse['data']['status']) || $decodedResponse['data']['status'] != 'active') {
            $error = __('Hop seller is not active. Please check your Hop account settings.');
            $this->_helper->log($error, true);
            return false;
        }
        return true;
    }

    /**
     * @param integer $zipCode
     * @param string|null $countryCode
     * @param bool $forceFromApi
     * @return \stdClass|null
     */
    public function getPickupPoints($zipCode, $countryCode = null, $forceFromApi = false)
    {
        $point = null;

        $countryCode = $countryCode ?: ($this->_helper->getStoreCountry() ?: 'AR');

        $collection = $this->pointCollectionFactory->create()
            ->addFieldToFilter('zip_code', $zipCode)
            ->addFieldToFilter('country_code', $countryCode);
        if ($collection->getSize()) {
            $point = $collection->getFirstItem();
            if (!$forceFromApi) {
                $pointData = $point->getPointData();
                $response = json_decode($pointData);
                if (!empty($response->data)){
                    return $response;
                }
            }
        }

        $sellerCode = $this->_helper->getSellerCode();

        $queryParams = [];
        if ($zipCode) {
            $queryParams['allow_deliveries'] = 1;
            $queryParams['zip_code'] = $zipCode;
        }
        $queryParams['country'] = $countryCode;
        $queryParams['seller_code'] = $sellerCode;

        $response = $this->curl("GET", "api.hopenvios.com.ar/api/{$this->pickupPointsApiVersion}/pickup_points", $queryParams);
        $decodedResponse = json_decode($response);

        $coords = null;
        if ($decodedResponse && $zipCode) {
            if (empty($decodedResponse->data)) {
                $coords = $this->getCoordinatesForZipCode($zipCode, $countryCode, $point);
                if ($coords) {
                    $proximityResult = $this->getPickupPointsByCoordinates($coords['lat'], $coords['lng']);
                    if ($proximityResult) {
                        $decodedResponse = $proximityResult['decoded'];
                        $response = $proximityResult['raw'];
                    }
                }
            }

            try {
                if ($point === null) {
                    $point = $this->pointFactory->create();
                }
                $point->setZipCode($zipCode);
                $point->setCountryCode($countryCode);
                $point->setPointData($response);
                if ($coords) {
                    $point->setLatitude($coords['lat']);
                    $point->setLongitude($coords['lng']);
                }
                $this->pointResource->save($point);
            } catch (\Exception $e) {
                $this->_helper->log($e->getMessage(), true);
            }
        }
        return $decodedResponse;
    }

    /**
     * @param string $countryCode
     * @return array<int, array{cp: string, lat: string, lng: string}>
     */
    public function fetchAllPostalCodes(string $countryCode): array
    {
        /**
         * This get request uses post body instead of query parameters because the API endpoint expects it that way, even for GET requests.
         * This is not a common practice, but we need to follow the API specifications to retrieve the postal codes correctly.
         */
        $response = $this->curl("GET", "api.hopenvios.com.ar/api/v1/postal_codes", [], json_encode(['country' => $countryCode]));
        if (!$response) {
            return [];
        }
        $postalCodes = json_decode($response, true);
        return is_array($postalCodes) ? $postalCodes : [];
    }

    /**
     * @param string $zipCode
     * @param string $countryCode
     * @param \Hop\Envios\Model\Point|null $point
     * @return array{lat: string, lng: string}|null
     */
    private function getCoordinatesForZipCode($zipCode, $countryCode, $point = null)
    {
        if ($point && $point->getId() && $point->getLatitude() && $point->getLongitude()) {
            return ['lat' => $point->getLatitude(), 'lng' => $point->getLongitude()];
        }

        $postalCodes = $this->fetchAllPostalCodes($countryCode);
        foreach ($postalCodes as $entry) {
            if (isset($entry['cp']) && (string)$entry['cp'] === (string)$zipCode) {
                $lat = $entry['lat'] ?? null;
                $lng = $entry['lng'] ?? null;
                if ($lat !== null && $lng !== null) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }

        return null;
    }

    /**
     * @param string $lat
     * @param string $lng
     * @return array{raw: string, decoded: \stdClass}|null
     */
    protected function getPickupPointsByCoordinates($lat, $lng)
    {
        $distances = [5, 10, 25];
        foreach ($distances as $distance){
            $response = $this->curl("GET", "api.hopenvios.com.ar/api/{$this->pickupPointsApiVersion}/pickup_points", [
                'lat'      => $lat,
                'lng'      => $lng,
                'distance' => $distance,
            ]);
            if (!$response) {
                continue;
            }
            $decoded = json_decode($response);
            if (!$decoded) {
                continue;
            }
            return ['raw' => $response, 'decoded' => $decoded];
        }
        return null;
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
    public function estimatePrice($originZipCode, $destinyZipCode, $sellerCode, $hopPointId, $shippingType = 'E', $package = [])
    {
        $width = $package['width'] ?? 0;
        $length = $package['length'] ?? 0;
        $height = $package['height'] ?? 0;
        $weight = $package['weight'] ?? 0;
        $value = $package['value'] ?? 0;

        $sizeCategory = $this->_helper->getSizeCategory();
        $queryParams = [
            'origin_zipcode'  => $originZipCode,
            'destiny_zipcode' => $destinyZipCode,
            'shipping_type'   => $shippingType,
            'weight'          => $weight,
            'seller_code'     => $sellerCode,
            'package'         => ['value' => $value],
        ];
        if ($width > 0 && $length > 0 && $height > 0) {
            $queryParams['package']['width']  = $width;
            $queryParams['package']['length'] = $length;
            $queryParams['package']['height'] = $height;
        }
        if ($sizeCategory) {
            $queryParams['package']['size_category'] = $sizeCategory;
        }
        if ($hopPointId) {
            $queryParams['pickup_point_id'] = $hopPointId;
        }

        $response = $this->curl("GET", "api.hopenvios.com.ar/api/v1/pricing/estimate", $queryParams);
        $responseObject = json_decode($response);

        if (isset($responseObject->data->amount)) {
            return $responseObject->data->amount;
        } else {
            if (!empty($responseObject->errors) && is_array($responseObject->errors)) {
                $entorno = $this->_helper->getProductivo() ? '' : 'sandbox-';
                $this->_helper->log('Url used: https://' . $entorno . 'api.hopenvios.com.ar/api/v1/pricing/estimate?' . http_build_query($queryParams), true);
                foreach ($responseObject->errors as $error) {
                    if (!empty($error->detail) && is_string($error->detail)) {
                        $this->_helper->log('Error estimating price: ' . $error->detail, true);
                    }
                }
            }
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
        $labelSize = $this->_helper->getLabelSize();
        $daysOffset = $this->_helper->getDaysOffset();
        $validateClientId = $this->_helper->getValidateClientId();
        $sizeCategory = $this->_helper->getSizeCategory();
        $storageCode = $this->_helper->getStorageCode();
        $packageData = $this->_helper->getPackageData($order);

        $hopData = $this->orderPickupPointRepository->getByOrderId((int)$order->getId());
        if (!$hopData) {
            $this->_helper->log(__('No Hop Data'), true);
            return false;
        }

        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        $params = [];

        if ($shippingAddress && $shippingAddress->getCountryId()){
            $params['country'] = $shippingAddress->getCountryId();
        } else {
            $params['country'] = $this->_helper->getStoreCountry() ?: 'AR';
        }
        $params['shipping_type'] = $shippingType;
        $params['reference_id'] = $sellerCode . '-' . $order->getIncrementId();
        $params['reference_2'] = '';
        $params['reference_3'] = '';
        $params['label_type'] = $labelType;
        if ($labelType != \Hop\Envios\Model\Config\Source\TypeLabelOption::TYPE_LABEL_ZPL2 && $labelSize) {
            $params['label_size'] = $labelSize;
        }
        $params['seller_code'] = $sellerCode;
        $params['storage_code'] = $storageCode;
        $params['days_offset'] = $daysOffset;
        $params['validate_client_id'] = $validateClientId;
        $params['pickup_point_id'] = $hopData->getPickupPointId() ?: 0;

        $paramClient = [];
        $paramClient['name'] = $order->getCustomerFirstname() . ' ' . $order->getCustomerLastname();
        $paramClient['email'] = $order->getCustomerEmail();
        $paramClient['id_type'] = 'D.N.I';

        if ($this->_helper->useCustomerTaxvat()) {
            $paramClient['id_number'] = $billingAddress->getVatId();
        } else {
            $paramClient['id_number'] = $order->getData($this->_helper->getCustomerDocumentAttribute());
        }

        $paramClient['telephone'] = ($billingAddress->getTelephone()) ? $billingAddress->getTelephone() : '';
        $params['client'] = $paramClient;

        $paramPackage = [];

        foreach(['length', 'height', 'width'] as $sizeField) {
            if (isset($packageData[$sizeField]) && $packageData[$sizeField] > 0) {
                $paramPackage[$sizeField] = $packageData[$sizeField];
            }
        }

        if ($sizeCategory && (empty($paramPackage['width']) || empty($paramPackage['length']) || empty($paramPackage['height']))) {
            $paramPackage['size_category'] = $sizeCategory;
        }

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

        $responseJson = $this->curl('POST', $url, [], $postFields);
        $responseObject = json_decode($responseJson);

        $this->_helper->log('Request POST: ' . $url);
        $this->_helper->log($responseObject, false, true);

        if (isset($responseObject->tracking_nro)) {
            return $responseJson;
        } else {
            // If the error is "reference_id already in use", try to find the existing shipment
            if (!empty($responseObject->error) &&
                strpos($responseObject->error, 'El elemento reference id ya est') !== false
            ) {
                $found = $this->findShipmentByReferenceId(
                    $params['reference_id'],
                    $order->getCustomerEmail(),
                    $order->getShippingAmount()
                );
                if ($found !== false) {
                    return $found;
                }
            }

            $error = __('Hubo un error al enviar su pedido a Hop: ');
            $error_list = [];
            if ($responseObject instanceof \stdClass) {
                $keys = get_object_vars($responseObject);
                foreach ($keys as $key) {
                    if (is_array($key)) {
                        foreach ($key as $message) {
                            if (is_string($message)) {
                                $error_list[] = $message . ". ";
                            }
                        }
                    }
                }
                if (!empty($responseObject->error)) {
                    $error_list[] = $responseObject->error . ". ";
                }
            } else if (is_string($responseObject)) {
                $error_list[] = $responseObject . ".";
            }
            $error .= implode(" ", $error_list);
            $this->_helper->log('Error:', true);
            $this->_helper->log($error, true);
            $this->messageManager->addErrorMessage($error);
            return array(
                'error' => $error
            );
        }
    }

    /**
     * Search for an existing shipment by reference ID and validate it belongs to the same order.
     *
     * @param string $referenceId
     * @param string $customerEmail
     * @param float $shippingAmount
     * @return string|false JSON string of the shipment on match, false otherwise
     */
    protected function findShipmentByReferenceId($referenceId, $customerEmail, $shippingAmount)
    {
        $this->_helper->log('Reference ID already in use, searching for existing shipment: ' . $referenceId);

        $searchResponseJson = $this->curl('GET', "api.hopenvios.com.ar/api/v1/search_shipments", ['reference' => $referenceId]);

        if ($searchResponseJson === false) {
            return false;
        }

        $searchResponse = json_decode($searchResponseJson, true);
        if (empty($searchResponse['data']) || !is_array($searchResponse['data'])) {
            return false;
        }

        $expectedPrice = number_format((float)$shippingAmount, 2, '.', '');

        foreach ($searchResponse['data'] as $shipment) {
            $foundPrice = isset($shipment['estimated_price'])
                ? number_format((float)$shipment['estimated_price'], 2, '.', '')
                : null;

            if (isset($shipment['client_email']) &&
                $shipment['client_email'] === $customerEmail &&
                $foundPrice !== null &&
                $foundPrice === $expectedPrice
            ) {
                $this->_helper->log('Found matching existing shipment for reference: ' . $referenceId);
                return json_encode($shipment);
            }
        }

        $this->_helper->log(
            'No matching shipment found for reference ' . $referenceId . '. ' .
            'Expected email: ' . $customerEmail . ', expected price: ' . $expectedPrice,
            true
        );

        return false;
    }
}
