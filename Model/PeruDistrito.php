<?php
declare(strict_types=1);

namespace Hop\Envios\Model;

use Magento\Framework\Model\AbstractModel;

class PeruDistrito extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Hop\Envios\Model\ResourceModel\PeruDistrito::class);
    }
}
