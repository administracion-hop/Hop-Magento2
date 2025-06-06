<?php
declare(strict_types=1);

namespace Hop\Envios\Model;

use Magento\Framework\Model\AbstractModel;

class SelectedPickUpPoint extends AbstractModel
{
    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(\Hop\Envios\Model\ResourceModel\SelectedPickUpPoint::class);
    }
}