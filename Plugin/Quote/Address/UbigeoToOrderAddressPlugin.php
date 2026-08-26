<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Quote\Address;

use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * Carries the Peru ubigeo address attributes from the quote address onto the order address.
 *
 * OrderAddressInterface has no setter for these codes, so Magento\Framework\Api\DataObjectHelper
 * silently drops them during ToOrderAddress::convert(). Setting them directly on the resulting
 * model bypasses that filtering and relies on the matching sales_order_address column to persist.
 */
class UbigeoToOrderAddressPlugin
{
    public function afterConvert(ToOrderAddress $subject, OrderAddressInterface $result, Address $quoteAddress): OrderAddressInterface
    {
        foreach ([PeruAddressAttributesManager::CODE_PROVINCIA, PeruAddressAttributesManager::CODE_DISTRITO] as $code) {
            $value = $quoteAddress->getData($code);

            if (($value === null || $value === '') && ($attribute = $quoteAddress->getCustomAttribute($code))) {
                $value = $attribute->getValue();
            }

            if ($value !== null && $value !== '') {
                $result->setData($code, $value);
            }
        }

        return $result;
    }
}
