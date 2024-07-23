<?php
namespace Improntus\Hop\Plugin\Widget;

use Magento\Backend\Block\Widget\Context AS Subject;
use Magento\Sales\Model\Order;
use Improntus\Hop\Helper\Data as DataHop;
use Magento\Framework\UrlInterface;

/**
 * Class Context
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Improntus\Hop\Plugin\Widget
 */
class Context
{
    /**
     * @var Order
     */
    protected $_order;

    /**
     * @var \Improntus\Hop\Helper\Data
     */
    protected $_helperHop;

    /**
     * @var UrlInterface
     */
    protected $_backendUrl;

    /**
     * @var
     */
    protected $_improntusHopFactory;

    /**
     * Context constructor.
     * @param Order $order
     * @param DataHop $helperHop
     * @param UrlInterface $urlInterface,
     * @param \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
     */
    public function __construct(
        Order $order,
        DataHop $helperHop,
        UrlInterface $urlInterface,
        \Improntus\Hop\Model\ImprontusHopFactory $improntusHopFactory
    )
    {
        $this->_order = $order;
        $this->_helperHop = $helperHop;
        $this->_backendUrl = $urlInterface;
        $this->_improntusHopFactory = $improntusHopFactory;
    }

    /**
     * @param Subject $subject
     * @param $buttonList
     * @return mixed
     */
    public function afterGetButtonList(
        Subject $subject,
        $buttonList
    )
    {
        if($this->_helperHop->isActive() && $subject->getRequest()->getFullActionName() == 'sales_order_view')
        {
            $orderId    = $subject->getRequest()->getParam('order_id');
            $order      = $this->_order->load($orderId);
            if ($order->getShippingMethod() == 'hop_hop')
            {
                $improntusHop = $this->_improntusHopFactory->create();
                $improntusHop = $improntusHop->getCollection()
                    ->addFieldToFilter('order_id', ['eq' => $orderId])
                    ->getFirstItem();
    
                $tracking_nro = '';
    
                if (count($improntusHop->getData()) > 0)
                {
                    $infoHop = $improntusHop->getInfoHop();
                    $infoHop = json_decode($infoHop ?? '');
                    $baseUrl = isset($infoHop->label_url) ? $infoHop->label_url : '';
                    $tracking_nro = isset($infoHop->tracking_nro) ? $infoHop->tracking_nro : '';
                }else
                {
                    $baseUrl = '';
                }
    
                if (!empty($baseUrl))
                {
                    $baseUrl = $this->_backendUrl->getUrl('hop/label/descargar',['order_id' => $orderId]);
    
                    $buttonList->add(
                        'descargar_etiqueta_hop',
                        [
                            'label'     => __('Descargar etiqueta HOP'),
                            'onclick' => "setLocation('{$baseUrl}')",
                            'class'     => 'primary hop-shipment-button'
                        ]
                    );
                    if(!empty($tracking_nro))
                    {
                        $trackingUrl = 'https://hopenvios.com.ar/segui-tu-envio?c='.$tracking_nro;
    
                        $buttonList->add(
                            'estado_hop',
                            [
                                'label'     => __('Estado HOP'),
                                'onclick' => "window.open('".$trackingUrl."', '_blank')",
                                'class'     => 'primary hop-shipment-button'
                            ]
                        );
                    }
                } else
                {
                    $baseUrl = $this->_backendUrl->getUrl('hop/order/view');
                    $buttonList->add(
                        'crear_etiqueta_hop',
                        [
                            'label'     => __('Enviar a HOP'),
                            'onclick' => "hopView.open('". $baseUrl."', ".$orderId.")",
                            'class'     => 'primary hop-shipment-button'
                        ]
                    );
                }
            }
        }

        return $buttonList;
    }
}
