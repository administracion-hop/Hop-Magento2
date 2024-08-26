<?php
namespace Hop\Envios\Model;

use Hop\Envios\Api\PointsInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;
use Hop\Envios\Model\Webservice;

/**
 * Class Points
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model
 */
class Points implements PointsInterface
{
    /**
     * @var Session
     */
    protected $_checkoutSession;

    /**
     * @var RequestInterface
     */
    protected $_request;

    /**
     * @var Webservice
     */
    protected $_webservice; // menze

    /**
     * Points constructor.
     * @param Session $checkoutSession
     * @param RequestInterface $request
     * @param Webservice $webservice
     */
    public function __construct(
        Session $checkoutSession,
        RequestInterface $request,
        Webservice $webservice
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_request = $request;
        $this->_webservice = $webservice;
    }

    /**
     * Returns hop points
     * @return string Greeting message with users response.
     * @api
     * @param string $zipCode
     * @author : Improntus
     */
    public function get($zipCode)
    {
        if($zipCode !== null && $zipCode !== ''){
            return json_encode($this->_webservice->getPickupPoints($zipCode));
        }
        return json_encode([]);
    }

    /**
     * Returns shipping estimation
     * @author : Improntus
     *
     * @api
     * @return string
     */
    public function estimate()
    {
        if($this->_request->getParam('hopPointPostcode'))
        {
            $this->_checkoutSession->setHopData(
                $this->_request->getParams()
            );
        }
    }
}
