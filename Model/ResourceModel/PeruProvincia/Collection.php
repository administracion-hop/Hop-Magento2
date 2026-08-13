<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel\PeruProvincia;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Hop\Envios\Model\PeruProvincia;
use Hop\Envios\Model\ResourceModel\PeruProvincia as PeruProvinciaResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(PeruProvincia::class, PeruProvinciaResource::class);
    }
}
