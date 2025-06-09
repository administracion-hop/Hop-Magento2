<?php
namespace Hop\Envios\Model\Config\Source;

use Hop\Envios\Model\Config\Source\AbstractStatus;
use \Magento\Sales\Model\Order;

class ProcessingStatus extends AbstractStatus
{
    /**
     * @var string
     */
    protected $state = Order::STATE_PROCESSING;
}