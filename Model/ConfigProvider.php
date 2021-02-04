<?php

namespace Improntus\Hop\Model;

use Improntus\Hop\Model\Webservice;
use Magento\Checkout\Model\ConfigProviderInterface;
use Improntus\Hop\Helper\Data;
use Magento\Framework\View\Asset\Repository;

/**
 * Class ConfigProvider
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Model
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Data
     */
    protected $_helper;

    /**
     * @var \Improntus\Hop\Model\Webservice
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
     * @param \Improntus\Hop\Model\Webservice $webservice
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
                'hop_icon' => $this->_assetRepo->getUrlWithParams('Improntus_Hop::images/hop_marker.png',[])
            ],
        ] : [];
    }

}
