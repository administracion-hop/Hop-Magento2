<?php
namespace Hop\Envios\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

class ProductAttributes implements \Magento\Framework\Data\OptionSourceInterface
{
    /** @var CollectionFactory */
    protected $attributeCollectionFactory;

    /**
     * @param CollectionFactory $attributeCollectionFactory
     */
    public function __construct(CollectionFactory $attributeCollectionFactory)
    {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options = [['value' => '', 'label' => __('-- Seleccione una opción --')]];
        $attributes = $this->attributeCollectionFactory->create()
            ->addFieldToFilter('frontend_input', ['in' => ['text', 'select', 'multiselect', 'textarea']])
            ->setOrder('attribute_code', 'ASC');

        foreach ($attributes as $attribute) {
            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => $attribute->getFrontendLabel() ? $attribute->getFrontendLabel() . " (". $attribute->getAttributeCode() . ")" : $attribute->getAttributeCode()
            ];
        }

        return $options;
    }
}