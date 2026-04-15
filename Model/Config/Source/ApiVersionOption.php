<?php

namespace Hop\Envios\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ApiVersionOption implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'v1', 'label' => __('V1')],
            ['value' => 'v3', 'label' => __('V3')],
        ];
    }
}
