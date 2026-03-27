<?php

namespace Hop\Envios\Plugin;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\QuotePickupPointRepository;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Class DefaultConfigProvider
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Plugin
 */
class DefaultConfigProvider
{
    /**
     * @var Hop\Envios\Helper\Data
     */
    protected $_helper;

    /**
     * @var CheckoutSession
     */
    protected $checkoutSession;

    /**
     * @var QuotePickupPointRepository
     */
    protected $quotePickupPointRepository;

    /**
     * DefaultConfigProvider constructor.
     * @param Data $helper
     * @param CheckoutSession $checkoutSession
     * @param QuotePickupPointRepository $quotePickupPointRepository
     */
    public function __construct(
        Data $helper,
        CheckoutSession $checkoutSession,
        QuotePickupPointRepository $quotePickupPointRepository
    ) {
        $this->_helper = $helper;
        $this->checkoutSession = $checkoutSession;
        $this->quotePickupPointRepository = $quotePickupPointRepository;
    }

    /**
     * @param \Magento\Checkout\Model\DefaultConfigProvider $subject
     * @param $config
     *
     * @return mixed
     */
    public function afterGetConfig(\Magento\Checkout\Model\DefaultConfigProvider $subject, $config)
    {

        $api_key = $this->_helper->getApiKey();

        if(!empty($api_key)){
            $config['hop']['api_key'] = !empty($api_key) ? $api_key : '';
        }

        // Load hop_data from selected pickup point if exists
        try {
            $quote = $this->checkoutSession->getQuote();
            if ($quote && $quote->getId()) {
                $hopData = $this->getHopDataFromSelectedPickupPoint((int)$quote->getId());
                if ($hopData) {
                    $config['quoteData']['hop_data'] = json_encode($hopData);
                }
            }
        } catch (\Exception $e) {
            // Silently fail - don't break checkout if hop data cannot be loaded
        }

        return $config;
    }

    /**
     * Get hop_data from selected pickup point
     *
     * @param int $quoteId
     * @return array|null
     */
    private function getHopDataFromSelectedPickupPoint(int $quoteId): ?array
    {
        $selectedPickupPoint = $this->quotePickupPointRepository->getByQuoteId($quoteId);
        if (!$selectedPickupPoint || !$selectedPickupPoint->getId()) {
            return null;
        }

        $pickupPointId = $selectedPickupPoint->getData('pickup_point_id');
        if (empty($pickupPointId)) {
            return null;
        }

        return [
            'hopPointId' => $pickupPointId,
            'hopPointPostcode' => $selectedPickupPoint->getData('original_zip_code') ?? '',
            'hopPointDescription' => $selectedPickupPoint->getData('original_shipping_description') ?? ''
        ];
    }
}
