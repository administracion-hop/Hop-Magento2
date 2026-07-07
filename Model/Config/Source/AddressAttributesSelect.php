<?php
namespace Hop\Envios\Model\Config\Source;

class AddressAttributesSelect extends AbstractAddressAttributes
{
    protected function getEmptyOptionLabel()
    {
        return __('-- Seleccionar --');
    }
}
