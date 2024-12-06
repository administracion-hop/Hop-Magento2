<?php
namespace Hop\Envios\Model;

use Magento\Framework\Model\AbstractModel;
use Hop\Envios\Model\ResourceModel\Token as TokenResourceModel;

class Token extends AbstractModel
{
    /**
     * Initialize resource model
     */
    protected function _construct()
    {
        $this->_init(TokenResourceModel::class);
    }
}
