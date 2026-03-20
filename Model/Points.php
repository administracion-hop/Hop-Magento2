<?php
namespace Hop\Envios\Model;

use Hop\Envios\Api\PointsInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Hop\Envios\Model\Webservice;

/**
 * Class Points
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model
 */
class Points implements PointsInterface
{
    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var RequestInterface
     */
    protected $_request;

    /**
     * @var Webservice
     */
    protected $_webservice; // menze

    /**
     * Points constructor.
     * @param Session $checkoutSession
     * @param RequestInterface $request
     * @param Webservice $webservice
     */
    public function __construct(
        Session $checkoutSession,
        RequestInterface $request,
        Webservice $webservice
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_request = $request;
        $this->_webservice = $webservice;
    }

    /**
     * Returns hop points
     * @return string Greeting message with users response.
     * @api
     * @param string $zipCode
     * @author : Hop Envíos
     */
    public function get($zipCode, $countryCode)
    {
        if($zipCode !== null && $zipCode !== ''){
            return json_encode($this->_webservice->getPickupPoints($zipCode, $countryCode));
        }
        return json_encode([]);
    }

    /**
     * Allowed parameters for hop data
     */
    private const ALLOWED_HOP_PARAMS = [
        'hopPointPostcode',      // Required - zipcode validation
        'hopPointId',            // Required - pickup point ID
        'hopPointReferenceName', // Shipping description
        'hopPointAddress',       // Shipping description
        'hopPointSchedules',     // Shipping description
        'hopPointName',          // Validation
        'hopPointDescription',   // Optional - alternative description
        'hopPointSeller',
        'hopPointProvidercode',
        'hopPointDistributorId',
        'hopPointAgencycode',
    ];

    /**
     * Returns shipping estimation
     * @author : Hop Envíos
     *
     * @api
     * @return string
     */
    public function estimate()
    {
        if (!$this->_request->getParam('hopPointPostcode')) {
            return json_encode(['success' => false, 'message' => 'Missing hopPointPostcode parameter']);
        }

        $allParams = $this->_request->getParams();
        $filteredParams = array_intersect_key(
            $allParams,
            array_flip(self::ALLOWED_HOP_PARAMS)
        );

        $this->_checkoutSession->setHopData($filteredParams);

        return json_encode(['success' => true, 'message' => 'Hop data saved successfully']);
    }
}
