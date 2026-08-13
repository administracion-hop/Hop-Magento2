<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class PeruProvincia extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('hop_peru_provincia', 'provincia_id');
    }
}
