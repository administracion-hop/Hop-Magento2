<?php
namespace Hop\Envios\Model\ResourceModel\Token;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Hop\Envios\Model\Token as TokenModel;
use Hop\Envios\Model\ResourceModel\Token as TokenResourceModel;

class Collection extends AbstractCollection
{
    /**
     * @var string Primary Key de la tabla
     */
    protected $_idFieldName = 'id';

    /**
     * Initialize collection
     */
    protected function _construct()
    {
        $this->_init(
            TokenModel::class,
            TokenResourceModel::class
        );
    }
}
