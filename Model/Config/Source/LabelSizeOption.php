<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class LabelSizeOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class LabelSizeOption implements \Magento\Framework\Data\OptionSourceInterface
{
    const SIZE_SMALL = 'S';
    const SIZE_MEDIUM = 'M';
    const SIZE_LARGE = 'L';
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::SIZE_SMALL, 'label' => __('Pequeña (5,25x10cm)')],
            ['value' => self::SIZE_MEDIUM, 'label' => __('Mediana (10x15cm)')],
            ['value' => self::SIZE_LARGE, 'label' => __('Grande (15x22,5cm)')]
        ];
    }
}
