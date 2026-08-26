<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Quote\Address;

use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Framework\Api\CustomAttributesDataInterface;
use Magento\Quote\Model\Quote\Address;

/**
 * Mirrors the Peru ubigeo custom attributes into flat data keys, so they persist on the
 * quote_address table columns and stay readable via plain getData() (Hop\Envios\Helper\Data
 * reads address attributes that way when resolving the ubigeo code).
 */
class UbigeoFlattenCustomAttributesPlugin
{
    /**
     * @param string|array $key
     * @param mixed $value
     */
    public function afterSetData(Address $subject, Address $result, $key, $value = null): Address
    {
        $touchesCustomAttributes = (is_string($key) && $key === CustomAttributesDataInterface::CUSTOM_ATTRIBUTES)
            || (is_array($key) && array_key_exists(CustomAttributesDataInterface::CUSTOM_ATTRIBUTES, $key));

        if (!$touchesCustomAttributes) {
            return $result;
        }

        foreach ([PeruAddressAttributesManager::CODE_PROVINCIA, PeruAddressAttributesManager::CODE_DISTRITO] as $code) {
            $attribute = $subject->getCustomAttribute($code);
            if ($attribute === null) {
                continue;
            }

            $attributeValue = $attribute->getValue();
            if ($attributeValue !== null && $attributeValue !== '') {
                $subject->setData($code, $attributeValue);
            }
        }

        return $result;
    }

    /**
     * @param string $attributeCode
     * @param mixed $attributeValue
     */
    public function afterSetCustomAttribute(
        Address $subject,
        Address $result,
        $attributeCode,
        $attributeValue = null
    ): Address {
        if (!in_array($attributeCode, [PeruAddressAttributesManager::CODE_PROVINCIA, PeruAddressAttributesManager::CODE_DISTRITO], true)) {
            return $result;
        }

        if ($attributeValue !== null && $attributeValue !== '') {
            $subject->setData($attributeCode, $attributeValue);
        }

        return $result;
    }
}
