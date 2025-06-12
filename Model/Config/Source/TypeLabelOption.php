<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class TypeLabelOption
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\Config\Source
 */
class TypeLabelOption implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'JPEG', 'label' => __('JPEG Default')],
            ['value' => 'ZPL2', 'label' => __('ZPL2 format')]
        ];
    }
}

