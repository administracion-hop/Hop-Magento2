<?php

namespace Improntus\Hop\Model\Config\Source;

use Magento\Sales\Model\ResourceModel\Order\Status\Collection;

/**
 * Class StatusOrderOption
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Model\Config\Source
 */
class StatusOrderOption implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * @var Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $statusCollectionFactory
     */
    protected $_statusCollectionFactory;

    /**
     * StatusOrderOption constructor.
     * @param \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $statusCollectionFactory
     */
    public function __construct(
        \Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory $statusCollectionFactory
    )
    {
        $this->_statusCollectionFactory = $statusCollectionFactory;
    }

    /**
     * @return array
     */
    public function toOptionArray()
    {
        $statuses = $this->_statusCollectionFactory->create()->toOptionArray();
        array_unshift($statuses, ['value' => '', 'label' => '']);

        return $statuses;
    }
}
