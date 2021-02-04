<?php
namespace Improntus\Hop\Model;

use Improntus\Hop\Api\PointsInterface;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\RequestInterface;

/**
 * Class Points
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Model
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
     * Points constructor.
     * @param Session $checkoutSession
     * @param RequestInterface $request
     */
    public function __construct(
        Session $checkoutSession,
        RequestInterface $request
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_request = $request;
    }

    /**
     * Returns hop points
     * @author : Improntus
     *
     * @api
     * @return string Greeting message with users response.
     */
    public function get()
    {
        return json_encode($this->_checkoutSession->getHopPickupPoints());
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
