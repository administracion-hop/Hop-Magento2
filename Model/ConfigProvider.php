<?php

namespace Hop\Envios\Model;

use Hop\Envios\Model\Webservice;
use Magento\Checkout\Model\ConfigProviderInterface;
use Hop\Envios\Helper\Data;
use Magento\Framework\View\Asset\Repository;

/**
 * Class ConfigProvider
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Data
     */
    protected $_helper;

    /**
     * @var \Hop\Envios\Model\Webservice
     */
    protected $_webservice;

    /**
     * Asset service
     *
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $_assetRepo;

    /**
     * ConfigProvider constructor.
     * @param \Hop\Envios\Model\Webservice $webservice
     * @param Data $data
     * @param Repository $assetRepo
     */
    public function __construct(
        Webservice $webservice,
        Data $data,
        Repository $assetRepo
    )
    {
        $this->_webservice = $webservice;
        $this->_helper = $data;
        $this->_assetRepo = $assetRepo;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {
        return $this->_helper->isActive() ? [
            'hop' => [
                'hop_icon' => $this->_assetRepo->getUrlWithParams('Hop_Envios::images/hop_marker.png',[])
            ],
        ] : [];
    }

}
