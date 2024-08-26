<?php

namespace Hop\Envios\Plugin;

use Hop\Envios\Helper\Data;

/**
 * Class DefaultConfigProvider
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Plugin
 */
class DefaultConfigProvider
{
    /**
     * @var Hop\Envios\Helper\Data
     */
    protected $_helper;

    /**
     * DefaultConfigProvider constructor.
     * @param Data $helper
     */
    public function __construct(
        Data $helper
    ) {
        $this->_helper = $helper;
    }

    /**
     * @param \Magento\Checkout\Model\DefaultConfigProvider $subject
     * @param $config
     *
     * @return mixed
     */
    public function afterGetConfig(\Magento\Checkout\Model\DefaultConfigProvider $subject, $config)
    {

        $api_key = $this->_helper->getApiKey();

        if(!empty($api_key)){
            $config['hop']['api_key'] = !empty($api_key) ? $api_key : '';
        }

        return $config;
    }
}

