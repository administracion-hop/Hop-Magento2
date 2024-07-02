<?php

namespace Improntus\Hop\Model\ResourceModel\Point;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;


class Collection extends AbstractCollection
{
    
    protected $_idFieldName = 'entity_id';


    protected function _construct()
    {
    
        $this->_init(\Improntus\Hop\Model\Point::class, \Improntus\Hop\Model\ResourceModel\Point::class);
    }
}
