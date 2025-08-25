<?php
namespace Hop\Envios\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

abstract class AbstractStatus implements OptionSourceInterface
{
    /**
     * @var CollectionFactory
     */
    protected $statusCollectionFactory;

    /**
     * @var string
     */
    protected $state;

    /**
     * @param CollectionFactory $statusCollectionFactory
     */
    public function __construct(
        CollectionFactory $statusCollectionFactory
    ) {
        $this->statusCollectionFactory = $statusCollectionFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array
     */
    public function toOptionArray()
    {
        $collection = $this->statusCollectionFactory->create()
            ->addStateFilter($this->state)
            ->toOptionArray();

        array_unshift($collection, ['value' => '', 'label' => __('-- Please Select --')]);

        return $collection;
    }
}