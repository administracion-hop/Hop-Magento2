<?php
namespace Hop\Envios\Model\Config\Source;

use Magento\Customer\Model\ResourceModel\Address\Attribute\CollectionFactory;

class AddressAttributes implements \Magento\Framework\Data\OptionSourceInterface
{
    /** @var CollectionFactory */
    protected $attributeCollectionFactory;

    public function __construct(CollectionFactory $attributeCollectionFactory)
    {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    public function toOptionArray()
    {
        $options = [['value' => '', 'label' => __('-- Usar código postal (predeterminado) --')]];
        $attributes = $this->attributeCollectionFactory->create()
            ->addFieldToFilter('frontend_input', ['in' => ['text', 'select']])
            ->setOrder('attribute_code', 'ASC');

        foreach ($attributes as $attribute) {
            $label = $attribute->getFrontendLabel()
                ? $attribute->getFrontendLabel() . ' (' . $attribute->getAttributeCode() . ')'
                : $attribute->getAttributeCode();
            $options[] = ['value' => $attribute->getAttributeCode(), 'label' => $label];
        }

        return $options;
    }
}
