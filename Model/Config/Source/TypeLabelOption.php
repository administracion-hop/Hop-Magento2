<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class TypeLabelOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class TypeLabelOption implements \Magento\Framework\Data\OptionSourceInterface
{
    const TYPE_LABEL_JPEG = 'JPEG';
    const TYPE_LABEL_ZPL2 = 'ZPL2';
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::TYPE_LABEL_JPEG, 'label' => __('JPEG Default')],
            ['value' => self::TYPE_LABEL_ZPL2, 'label' => __('ZPL2 format')]
        ];
    }
}

