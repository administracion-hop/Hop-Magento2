<?php
namespace Hop\Envios\Block\Order;

use Magento\Sales\Model\Order\Address;
use Magento\Framework\View\Element\Template\Context as TemplateContext;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;

/**
 * Class Info
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Block\Order
 */
class Info extends \Magento\Sales\Block\Order\Info
{
    /**
     * @var string
     */
    protected $_template = 'Hop_Envios::order/info.phtml';

    /**
     * Core registry
     *
     * @var \Magento\Framework\Registry
     */
    protected $coreRegistry = null;

    /**
     * @var \Magento\Payment\Helper\Data
     */
    protected $paymentHelper;

    /**
     * @var AddressRenderer
     */
    protected $addressRenderer;

    /**
     * @var
     */
    protected $_hopEnviosFactory;

    /**
     * @param TemplateContext $context
     * @param Registry $registry
     * @param PaymentHelper $paymentHelper
     * @param AddressRenderer $addressRenderer
     * @param array $data
     * @param \Hop\Envios\Model\HopEnviosFactory $hopEnviosFactory
     */
    public function __construct(
        TemplateContext $context,
        Registry $registry,
        PaymentHelper $paymentHelper,
        AddressRenderer $addressRenderer,
        \Hop\Envios\Model\HopEnviosFactory $hopEnviosFactory,
        array $data = []
    ) {
        $this->_hopEnviosFactory = $hopEnviosFactory;
        parent::__construct($context,$registry, $paymentHelper, $addressRenderer, $data);
    }

    /**
     * Retrieve current order model instance
     *
     * @return \Magento\Sales\Model\Order
     */
    public function getOrder()
    {
        return $this->coreRegistry->registry('current_order');
    }

    public function getTrackingUrlStatus()
    {
        $order = $this->getOrder();

        $hopEnvios = $this->_hopEnviosFactory->create();
        $hopEnvios = $hopEnvios->getCollection()
            ->addFieldToFilter('order_id', ['eq' => $order->getEntityId()])
            ->getFirstItem();

        $tracking_nro = '';

        if (count($hopEnvios->getData()) > 0)
        {
            $infoHop = $hopEnvios->getInfoHop();
            $infoHop = json_decode($infoHop ?? '');
            $tracking_nro = isset($infoHop->tracking_nro) ? $infoHop->tracking_nro : '';
        }

        if($order->getShippingMethod() == 'hop_hop' && !empty($tracking_nro))
        {
            $baseUrl = 'https://hopenvios.com.ar/segui-tu-envio?c='.$tracking_nro;
            return $baseUrl;
        }

        return '';
    }

}

