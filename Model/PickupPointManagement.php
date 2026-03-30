<?php
namespace Hop\Envios\Model;

use Hop\Envios\Api\PickupPointManagementInterface;
use Hop\Envios\Model\QuotePickupPointRepository;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Hop\Envios\Model\Webservice;

/**
 * Class PickupPointManagement
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model
 */
class PickupPointManagement implements PickupPointManagementInterface
{
    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var RequestInterface
     */
    protected $request;

    /**
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var QuotePickupPointRepository
     */
    protected $quotePickupPointRepository;

    /**
     * PickupPointManagement constructor.
     *
     * @param Session $checkoutSession
     * @param RequestInterface $request
     * @param Webservice $webservice
     * @param QuotePickupPointRepository $quotePickupPointRepository
     */
    public function __construct(
        Session $checkoutSession,
        RequestInterface $request,
        Webservice $webservice,
        QuotePickupPointRepository $quotePickupPointRepository
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->request = $request;
        $this->webservice = $webservice;
        $this->quotePickupPointRepository = $quotePickupPointRepository;
    }

    /**
     * Returns the available pickup points for the given zip code.
     *
     * @api
     * @param string $zipCode
     * @param string|null $countryCode
     * @return string
     */
    public function get($zipCode, $countryCode = null)
    {
        if ($zipCode !== null && $zipCode !== '') {
            $normalizedCountryCode = ($countryCode !== null && $countryCode !== '') ? $countryCode : null;
            return json_encode($this->webservice->getPickupPoints($zipCode, $normalizedCountryCode));
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
     * Saves the selected pickup point data in session and triggers rate recalculation.
     *
     * @api
     * @return string
     */
    public function estimate()
    {
        if (!$this->request->getParam('hopPointPostcode')) {
            return json_encode(['success' => false, 'message' => 'Missing hopPointPostcode parameter']);
        }

        $allParams = $this->request->getParams();
        $filteredParams = array_intersect_key(
            $allParams,
            array_flip(self::ALLOWED_HOP_PARAMS)
        );

        $this->checkoutSession->setHopData($filteredParams);

        return json_encode(['success' => true, 'message' => 'Hop data saved successfully']);
    }

    /**
     * Returns the currently selected pickup point for the active quote.
     *
     * @api
     * @return string JSON with hopPointId, hopPointPostcode and hopPointDescription, or null
     */
    public function getSelectedPoint()
    {
        $quote = $this->checkoutSession->getQuote();

        // Primero intenta leer desde la DB (fuente canónica)
        if ($quote && $quote->getId()) {
            $selectedPickupPoint = $this->quotePickupPointRepository->getByQuoteId((int)$quote->getId());
            if ($selectedPickupPoint && $selectedPickupPoint->getId()) {
                $pickupPointId = $selectedPickupPoint->getData('pickup_point_id');
                if (!empty($pickupPointId)) {
                    return json_encode([
                        'hopPointId'          => $pickupPointId,
                        'hopPointPostcode'    => $selectedPickupPoint->getData('original_zip_code') ?? '',
                        'hopPointDescription' => $selectedPickupPoint->getData('original_shipping_description') ?? ''
                    ]);
                }
            }
        }

        $hopData = $this->checkoutSession->getHopData();
        if (!empty($hopData['hopPointId'])) {
            return json_encode([
                'hopPointId'          => $hopData['hopPointId'],
                'hopPointPostcode'    => $hopData['hopPointPostcode'] ?? '',
                'hopPointDescription' => $hopData['hopPointDescription'] ?? ''
            ]);
        }

        return json_encode(null);
    }
}
