<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class StorageCodeOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class StorageCodeOption implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * @return array|array[]
     */
    public function toOptionArray()
    {
        return [
            ['value' => 'DEPOSITO', 'label' => __('DEPOSITO')],
            ['value' => 'OFICINA', 'label' => __('OFICINA')]
        ];
    }
}


