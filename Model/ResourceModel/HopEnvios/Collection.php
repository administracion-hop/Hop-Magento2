<?php

namespace Hop\Envios\Model\ResourceModel\HopEnvios;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Class Collection
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\ResourceModel\HopEnvios
 */
class Collection extends AbstractCollection
{
    /**
     * @var string Primary Key de la tabla
     */
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        parent::_construct();
        $this->_init('Hop\Envios\Model\HopEnvios', 'Hop\Envios\Model\ResourceModel\HopEnvios');
    }
}
