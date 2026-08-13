<?php

namespace Hop\Envios\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class UbigeoSourceOption implements OptionSourceInterface
{
    const FIELD = 'field';
    const MAPPING = 'mapping';

    public function toOptionArray()
    {
        return [
            ['value' => self::FIELD, 'label' => __('Campo de Ubigeo')],
            ['value' => self::MAPPING, 'label' => __('Mapeo de Distrito y Provincia')],
        ];
    }
}
