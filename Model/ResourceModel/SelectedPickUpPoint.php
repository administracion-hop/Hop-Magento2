<?php
declare(strict_types=1);

namespace Hop\Envios\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class SelectedPickUpPoint extends AbstractDb
{
    /**
     * Table name
     */
    const MAIN_TABLE = 'hop_envios_selected_pickup_point';

    /**
     * Primary key field
     */
    const ID_FIELD_NAME = 'id';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init(self::MAIN_TABLE, self::ID_FIELD_NAME);
    }
}