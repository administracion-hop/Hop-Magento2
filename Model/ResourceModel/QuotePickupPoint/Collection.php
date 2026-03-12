<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel\QuotePickupPoint;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /**
     * ID field name
     *
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Define resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            \Hop\Envios\Model\QuotePickupPoint::class,
            \Hop\Envios\Model\ResourceModel\QuotePickupPoint::class
        );
    }
}
