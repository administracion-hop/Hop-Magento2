<?php
declare(strict_types=1);

namespace Hop\Envios\Plugin\Quote\Address;

use Hop\Envios\Model\Ubigeo\PeruAddressAttributesManager;
use Magento\Customer\Model\AttributeMetadataConverter;
use Magento\Customer\Model\Indexer\Address\AttributeProvider;
use Magento\Eav\Api\AttributeRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Address\CustomAttributeListInterface;

/**
 * Registers the Peru ubigeo address attributes as valid quote address custom attributes,
 * so they survive shipping rate estimation and shipping-information save.
 */
class UbigeoCustomAttributeListPlugin
{
    public function __construct(private readonly AttributeRepositoryInterface $attributeRepository,
        private readonly AttributeMetadataConverter $attributeMetadataConverter)
    {
    }

    public function afterGetAttributes(CustomAttributeListInterface $subject, array $result): array
    {
        foreach ([PeruAddressAttributesManager::CODE_PROVINCIA, PeruAddressAttributesManager::CODE_DISTRITO] as $code) {
            try {
                $attribute = $this->attributeRepository->get(AttributeProvider::ENTITY, $code);
            } catch (NoSuchEntityException $e) {
                continue;
            }

            $result[$code] = $this->attributeMetadataConverter->createMetadataAttribute($attribute);
        }

        return $result;
    }
}
