<?php
namespace Improntus\Hop\Model;

use Magento\Framework\Model\AbstractModel;

class Point extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Improntus\Hop\Model\ResourceModel\Point::class);
    }
}
