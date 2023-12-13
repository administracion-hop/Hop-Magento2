<?php
namespace Improntus\Hop\Block\Order;

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
 * @package Improntus\Hop\Block\Order
 */
class Info extends \Magento\Sales\Block\Order\Info
{
    /**
     * @var string
     */
    protected $_template = 'Improntus_Hop::order/info.phtml';

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
    protected $_improntusHopFactory;

    /**
     * @param TemplateContext $context
     * @param Registry $registry
     * @param PaymentHelper $paymentHelper
     * @param AddressRenderer $addressRenderer
     * @param array $data
     * @param \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
     */
    public function __construct(
        TemplateContext $context,
        Registry $registry,
        PaymentHelper $paymentHelper,
        AddressRenderer $addressRenderer,
        \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory,
        array $data = []
    ) {
        $this->_improntusHopFactory = $improntusHopFactory;
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

        $improntusHop = $this->_improntusHopFactory->create();
        $improntusHop = $improntusHop->getCollection()
            ->addFieldToFilter('order_id', ['eq' => $order->getEntityId()])
            ->getFirstItem();

        $tracking_nro = '';

        if (count($improntusHop->getData()) > 0)
        {
            $infoHop = $improntusHop->getInfoHop();
            $infoHop = json_decode($infoHop ?? '');
            $tracking_nro = isset($infoHop->tracking_nro) ? $infoHop->tracking_nro : '';
        }

        if($order->getShippingMethod() == 'hop_hop' && !empty($tracking_nro))
        {
            $baseUrl = 'https://hopenvios.com.ar/tracking.php?tracking_code='.$tracking_nro;
            return $baseUrl;
        }

        return '';
    }

}

