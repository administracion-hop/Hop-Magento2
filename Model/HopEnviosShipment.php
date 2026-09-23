<?php

namespace Hop\Envios\Model;

use Magento\Framework\Model\AbstractModel;

class HopEnviosShipment extends AbstractModel
{
    protected $_eventPrefix = 'hop_envios_shipment_event';
    protected $_eventObject = 'hop_envios_shipment_object';

    protected function _construct()
    {
        $this->_init('Hop\Envios\Model\ResourceModel\HopEnviosShipment');
    }
}
