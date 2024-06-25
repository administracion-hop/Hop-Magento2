<?php

namespace Improntus\Hop\Model\ResourceModel;

class Point extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{

    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context
    ) {
        parent::__construct($context);
    }

    public function _construct()
    {
        $this->_init('hop_pickup_points', 'entity_id');
    }
}