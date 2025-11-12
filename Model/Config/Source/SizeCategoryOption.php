<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class SizeCategoryOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class SizeCategoryOption implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => '', 'label' => __('-- Seleccione una opción --')],
            ['value' => '1', 'label' => __('1')],
            ['value' => '2', 'label' => __('2')],
            ['value' => '3', 'label' => __('3')],
            ['value' => '4', 'label' => __('4')],
            ['value' => '5', 'label' => __('5')],
            ['value' => '6', 'label' => __('6')],
            ['value' => '7', 'label' => __('7')],
            ['value' => '8', 'label' => __('8')],
            ['value' => '9', 'label' => __('9')],
            ['value' => '10', 'label' => __('10')],
            ['value' => '11', 'label' => __('11')],
            ['value' => '12', 'label' => __('12')],
            ['value' => '13', 'label' => __('13')]
        ];
    }
}
