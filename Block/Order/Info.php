<?php
namespace Hop\Envios\Block\Order;

use Magento\Sales\Model\Order\Address;
use Magento\Framework\View\Element\Template\Context as TemplateContext;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;
use Hop\Envios\Model\HopEnviosRepository;

/**
 * Class Info
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
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
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

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
        HopEnviosRepository $hopEnviosRepository,
        array $data = []
    ) {
        $this->hopEnviosRepository = $hopEnviosRepository;
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
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($order->getId());

        $tracking_nro = '';

        if ($hopEnvios) {
            $infoHop = $hopEnvios->getInfoHop();
            $infoHop = json_decode($infoHop ?? '');
            $tracking_nro = isset($infoHop->tracking_nro) ? $infoHop->tracking_nro : '';
        }

        if ($order->getShippingMethod() == 'hop_hop' && !empty($tracking_nro)) {
            $baseUrl = 'https://hopenvios.com.ar/segui-tu-envio?c='.$tracking_nro;
            return $baseUrl;
        }

        return '';
    }

}

