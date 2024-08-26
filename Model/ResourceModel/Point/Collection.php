<?php

namespace Hop\Envios\Model\ResourceModel\Point;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;


class Collection extends AbstractCollection
{
    
    protected $_idFieldName = 'entity_id';


    protected function _construct()
    {
    
        $this->_init(\Hop\Envios\Model\Point::class, \Hop\Envios\Model\ResourceModel\Point::class);
    }
}
