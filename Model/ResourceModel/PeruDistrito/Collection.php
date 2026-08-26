<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel\PeruDistrito;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Hop\Envios\Model\PeruDistrito;
use Hop\Envios\Model\ResourceModel\PeruDistrito as PeruDistritoResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(PeruDistrito::class, PeruDistritoResource::class);
    }
}
