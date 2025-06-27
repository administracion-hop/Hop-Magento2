<?php

namespace Hop\Envios\Model;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;

/**
 * Class HopEnvios
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model
 */
class HopEnvios extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'hop_envios_event';

    /**
     * Parameter name in event
     *
     * In observe method you can use $observer->getEvent()->getObject() in this case
     *
     * @var string
     */
    protected $_eventObject = 'hop_envios_object';

    /**
     * True if data changed
     *
     * @var bool
     */
    protected $_isStatusChanged = false;

    /**
     * Inicia el resource model
     */
    protected function _construct()
    {
        $this->_init('Hop\Envios\Model\ResourceModel\HopEnvios');
    }
}