<?php
/**
 * Collection: SelectedPickUpPoint
 * Path: app/code/Hop/Envios/Model/ResourceModel/SelectedPickUpPoint/Collection.php
 */
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel\SelectedPickUpPoint;

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
            \Hop\Envios\Model\SelectedPickUpPoint::class,
            \Hop\Envios\Model\ResourceModel\SelectedPickUpPoint::class
        );
    }
}