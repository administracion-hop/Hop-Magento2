<?php

namespace Hop\Envios\Model\Config\Source;

use Magento\Sales\Model\ResourceModel\Order\Status\Collection;

/**
 * Class StatusOrderOption
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Config\Source
 */
class StatusOrderOption implements \Magento\Framework\Data\OptionSourceInterface
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
