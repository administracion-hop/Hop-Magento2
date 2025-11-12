<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class DocumentAttributeOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class DocumentAttributeOption implements \Magento\Framework\Data\OptionSourceInterface
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

