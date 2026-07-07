<?php
namespace Hop\Envios\Model\Config\Source;

class AddressAttributes extends AbstractAddressAttributes
{
    protected function getEmptyOptionLabel()
    {
        return __('-- Usar código postal (predeterminado) --');
    }
}
