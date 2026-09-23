<?php

namespace Hop\Envios\Model\ResourceModel\HopEnviosShipment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Hop\Envios\Model\HopEnviosShipment;
use Hop\Envios\Model\ResourceModel\HopEnviosShipment as HopEnviosShipmentResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(HopEnviosShipment::class, HopEnviosShipmentResource::class);
    }
}
