<?php

namespace Hop\Envios\Model\Shipping;

use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\Webservice;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Hop shipping price as charged to the buyer: Hop's estimate adjusted by the carrier's
 * percentage_rate / fixed_value settings. Shared by the carrier (checkout) and the buyer's
 * pickup point change, so both always price a point the same way.
 */
class HopPriceCalculator
{
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Webservice
     */
    protected $webservice;

    /**
     * @var HopHelper
     */
    protected $helper;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Webservice $webservice
     * @param HopHelper $helper
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Webservice $webservice,
        HopHelper $helper
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->webservice = $webservice;
        $this->helper = $helper;
    }

    /**
     * Applies carriers/hop/percentage_rate and carriers/hop/fixed_value to Hop's raw cost.
     *
     * @param float $hopCost
     * @param int|null $storeId
     * @return float
     */
    public function adjust(float $hopCost, $storeId = null): float
    {
        $percentageRate = $this->getCarrierConfig('percentage_rate', $storeId);
        $fixedValue = $this->getCarrierConfig('fixed_value', $storeId);

        $adjustedShippingCost = !empty($percentageRate) ? $hopCost * $percentageRate : $hopCost;
        if (!empty($fixedValue)) {
            $adjustedShippingCost += (float)$fixedValue;
        }

        return max(0, $adjustedShippingCost);
    }

    /**
     * Quotes one pickup point with Hop and returns the adjusted price, or null if Hop
     * didn't return a price (API error, timeout, invalid data).
     *
     * @param array $package width, length, height, weight (g), value
     * @param string $destZipCode
     * @param string $hopPointId
     * @param int|null $storeId
     * @return float|null
     */
    public function quote(array $package, string $destZipCode, string $hopPointId, $storeId = null): ?float
    {
        $this->webservice->setStoreId($storeId);
        $hopCost = $this->webservice->estimatePrice(
            $this->helper->getOriginZipcode($storeId),
            $destZipCode,
            $this->helper->getSellerCode($storeId),
            $hopPointId,
            'E',
            $package
        );

        if ($hopCost === false) {
            return null;
        }

        return $this->adjust((float)$hopCost, $storeId);
    }

    /**
     * @param string $field
     * @param int|null $storeId
     * @return mixed
     */
    protected function getCarrierConfig(string $field, $storeId)
    {
        return $this->scopeConfig->getValue('carriers/hop/' . $field, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
