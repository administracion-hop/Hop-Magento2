<?php

namespace Hop\Envios\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class HopEnviosShipment extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('hop_envios_shipment', 'entity_id');
    }
}
