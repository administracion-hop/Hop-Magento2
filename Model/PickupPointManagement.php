<?php
namespace Hop\Envios\Model;

use Hop\Envios\Api\PickupPointManagementInterface;
use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\QuotePickupPointRepository;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Hop\Envios\Model\Webservice;
use Magento\Quote\Api\CartRepositoryInterface;

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
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var HopHelper
     */
    protected $helper;

    /**
     * PickupPointManagement constructor.
     *
     * @param Session $checkoutSession
     * @param RequestInterface $request
     * @param Webservice $webservice
     * @param QuotePickupPointRepository $quotePickupPointRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param HopHelper $helper
     */
    public function __construct(
        Session $checkoutSession,
        RequestInterface $request,
        Webservice $webservice,
        QuotePickupPointRepository $quotePickupPointRepository,
        CartRepositoryInterface $quoteRepository,
        HopHelper $helper
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->request = $request;
        $this->webservice = $webservice;
        $this->quotePickupPointRepository = $quotePickupPointRepository;
        $this->quoteRepository = $quoteRepository;
        $this->helper = $helper;
    }

    /**
     * Returns the available pickup points for the given zip code.
     *
     * $regionId/$provincia/$distrito let the frontend pass the live-typed address
     * directly (see Hop/Envios/view/frontend/web/js/view/hop.js::getHopPoints), since the
     * checkout session's quote address is only persisted once shipping-information is
     * saved - too late for the pickup-point picker, which opens earlier in the flow.
     *
     * @api
     * @param string $zipCode
     * @param string|null $countryCode
     * @param int|null $regionId
     * @param string|null $provincia
     * @param string|null $distrito
     * @return string
     */
    public function get($zipCode, $countryCode = null, $regionId = null, $provincia = null, $distrito = null)
    {
        if ($this->helper->isUbigeoConfigured()) {
            $ubigeoValue = null;

            if ($regionId && $provincia && $distrito) {
                $ubigeoValue = $this->helper->getUbigeoFromDistritoProvinciaValues(
                    (int)$regionId,
                    $provincia,
                    $distrito
                );
            }

            if (!$ubigeoValue) {
                $shippingAddress = $this->checkoutSession->getQuote()->getShippingAddress();
                if ($shippingAddress) {
                    $ubigeoValue = $this->helper->getUbigeoFromAddress($shippingAddress);
                }
            }

            if ($ubigeoValue) {
                $zipCode = $ubigeoValue;
            }
        }

        // '0' is the placeholder the frontend sends when it has no literal zip code and
        // relies entirely on regionId/provincia/distrito (see hop.js::loadHopPoints). If the
        // mapping lookup above didn't resolve a real ubigeo, treat it as no zip code at all.
        if ($zipCode !== null && $zipCode !== '' && $zipCode !== '0') {
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
        $this->persistPickupPointToDb($filteredParams);

        return json_encode(['success' => true, 'message' => 'Hop data saved successfully']);
    }

    /**
     * Persists the pickup point to DB so collectRates() can recover it if the session is lost.
     */
    private function persistPickupPointToDb(array $filteredParams): void
    {
        if (empty($filteredParams['hopPointId'])) {
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote->getId() && $quote->getItemsCount() > 0) {
                $this->quoteRepository->save($quote);
            }
            if (!$quote->getId()) {
                return;
            }

            if (!empty($filteredParams['hopPointDescription'])) {
                $shippingDescription = $filteredParams['hopPointDescription'];
            } elseif (!empty($filteredParams['hopPointReferenceName'])) {
                $shippingDescription = 'Retirá tu pedido en: ' .
                    $filteredParams['hopPointReferenceName'] .
                    ' (' . ($filteredParams['hopPointAddress'] ?? '') . ') ' .
                    ' - Horario: ' . ($filteredParams['hopPointSchedules'] ?? '');
            } else {
                $shippingDescription = '';
            }

            $selectedPickupPoint = $this->quotePickupPointRepository->getByQuoteId((int)$quote->getId());
            if (!$selectedPickupPoint) {
                $selectedPickupPoint = $this->quotePickupPointRepository->create();
                $selectedPickupPoint->setQuoteId($quote->getId());
            }
            $selectedPickupPoint->setPickupPointId($filteredParams['hopPointId']);
            $selectedPickupPoint->setOriginalPickupPointId($filteredParams['hopPointId']);
            $selectedPickupPoint->setOriginalShippingDescription($shippingDescription);
            $selectedPickupPoint->setOriginalZipCode($filteredParams['hopPointPostcode'] ?? '');
            $this->quotePickupPointRepository->save($selectedPickupPoint);
        } catch (\Exception $e) {
            // DB failure is non-fatal; session write already succeeded
        }
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
