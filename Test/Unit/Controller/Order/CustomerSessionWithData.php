<?php

namespace Hop\Envios\Test\Unit\Controller\Order;

use Magento\Customer\Model\Session;

abstract class CustomerSessionWithData extends Session
{
    /**
     * @param string|array $key
     * @param mixed $value
     * @return $this
     */
    abstract public function setData($key, $value = null);
}
