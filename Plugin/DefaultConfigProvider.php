<?php

namespace Hop\Envios\Plugin;

use Hop\Envios\Helper\Data;
use Hop\Envios\Model\SelectedPickupPointRepository;
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
     * @var SelectedPickupPointRepository
     */
    protected $selectedPickupPointRepository;

    /**
     * DefaultConfigProvider constructor.
     * @param Data $helper
     * @param CheckoutSession $checkoutSession
     * @param SelectedPickupPointRepository $selectedPickupPointRepository
     */
    public function __construct(
        Data $helper,
        CheckoutSession $checkoutSession,
        SelectedPickupPointRepository $selectedPickupPointRepository
    ) {
        $this->_helper = $helper;
        $this->checkoutSession = $checkoutSession;
        $this->selectedPickupPointRepository = $selectedPickupPointRepository;
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
        $selectedPickupPoint = $this->selectedPickupPointRepository->getByQuoteId($quoteId);
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
