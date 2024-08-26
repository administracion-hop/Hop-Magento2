<?php

namespace Hop\Envios\Model\Config\Source;

/**
 * Class StorageCodeOption
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\Config\Source
 */
class StorageCodeOption implements \Magento\Framework\Option\ArrayInterface
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


