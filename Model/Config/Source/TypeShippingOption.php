<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class TypeShippingOption
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\Config\Source
 */
class TypeShippingOption implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'E', 'label' => __('Pickup')],
            ['value' => 'R', 'label' => __('Drop-off')],
            ['value' => 'RE', 'label' => __('Pickup/Drop-off')]
        ];
    }
}


