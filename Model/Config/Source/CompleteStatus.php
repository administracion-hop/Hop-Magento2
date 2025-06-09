<?php
namespace Hop\Envios\Model\Config\Source;

use Hop\Envios\Model\Config\Source\AbstractStatus;
use \Magento\Sales\Model\Order;

class CompleteStatus extends AbstractStatus
{
    /**
     * @var string
     */
    protected $state = Order::STATE_COMPLETE;
}