<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Customer\ViewModel;

use Magento\Customer\ViewModel\Address;
use Hop\Envios\Helper\Data;
class AddressPlugin
{

    /**
     * @var Data
     */
    private $configHelper;
    /**
     * AddressPlugin constructor.
     *
     * @param Data $configHelper
     */
    public function __construct(
        Data $configHelper
    ) {
        $this->configHelper = $configHelper;
    }
    /**
     * Plugin after addressGetAttributeValidationClass
     *
     * @param Address $subject
     * @param string $result
     * @param string $attributeCode
     * @return string
     */
    public function afterAddressGetAttributeValidationClass(
        Address $subject,
        string $result,
        string $attributeCode
    ): string {

        if ($attributeCode == 'vat_id') {
            $isVatRequired = $this->configHelper->useCustomerTaxvat();
            $result .= $isVatRequired ? ' required-entry' : '';
        }
        return $result;
    }
}
