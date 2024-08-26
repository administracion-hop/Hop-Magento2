<?php
namespace Hop\Envios\Model;

use Magento\Framework\Model\AbstractModel;

class Point extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Hop\Envios\Model\ResourceModel\Point::class);
    }
}
