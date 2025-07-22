<?php
namespace Hop\Envios\Plugin\Widget;

use Magento\Backend\Block\Widget\Context AS Subject;
use Magento\Sales\Model\Order;
use Hop\Envios\Helper\Data as DataHop;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Framework\UrlInterface;
use Hop\Envios\Model\SelectedPickupPointRepository;

/**
 * Class Context
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Plugin\Widget
 */
class Context
{
    /**
     * @var Order
     */
    protected $order;

    /**
     * @var \Hop\Envios\Helper\Data
     */
    protected $helperHop;

    /**
     * @var UrlInterface
     */
    protected $backendUrl;

    /**
     * @var HopEnviosRepository
     */
    protected $hopEnviosRepository;

    /**
     * @var SelectedPickupPointRepository
     */
    protected $selectedPickupPointRepository;

    /**
     * Context constructor.
     * @param Order $order
     * @param DataHop $helperHop
     * @param UrlInterface $urlInterface,
     * @param HopEnviosRepository $hopEnviosRepository
     * @param SelectedPickupPointRepository $selectedPickupPointRepository
     */
    public function __construct(
        Order $order,
        DataHop $helperHop,
        UrlInterface $urlInterface,
        HopEnviosRepository $hopEnviosRepository,
        SelectedPickupPointRepository $selectedPickupPointRepository
    )
    {
        $this->order = $order;
        $this->helperHop = $helperHop;
        $this->backendUrl = $urlInterface;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->selectedPickupPointRepository = $selectedPickupPointRepository;
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
        if($this->helperHop->isActive() && $subject->getRequest()->getFullActionName() == 'sales_order_view')
        {
            $orderId    = $subject->getRequest()->getParam('order_id');
            $order      = $this->order->load($orderId);
            if ($order->getShippingMethod() == 'hop_hop')
            {
                $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);
                $tracking_nro = '';

                if ($hopEnvios) {
                    $infoHop = $hopEnvios->getInfoHop();
                    $infoHop = json_decode($infoHop ?? '');
                    $baseUrl = isset($infoHop->label_url) ? $infoHop->label_url : '';
                    $tracking_nro = isset($infoHop->tracking_nro) ? $infoHop->tracking_nro : '';
                } else {
                    $baseUrl = '';
                }

                if (!empty($baseUrl)) {
                    $baseUrl = $this->backendUrl->getUrl('hop/label/descargar',['order_id' => $orderId]);

                    $buttonList->add(
                        'descargar_etiqueta_hop',
                        [
                            'label'     => __('Descargar etiqueta HOP'),
                            'onclick' => "setLocation('{$baseUrl}')",
                            'class'     => 'primary hop-shipment-button'
                        ]
                    );
                    if (!empty($tracking_nro)) {
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
                } else {
                    $baseUrl = $this->backendUrl->getUrl('hop/order/view');
                    $buttonList->add(
                        'cambiar_punto_hop',
                        [
                            'label'     => __('Cambiar punto Hop'),
                            'onclick' => "hopView.open('". $baseUrl."', ".$orderId.")",
                            'class'     => 'primary hop-shipment-button'
                        ]
                    );
                    $selectedPickupPoint = $this->selectedPickupPointRepository->getByQuoteId($order->getQuoteId());
                    if ($selectedPickupPoint && $selectedPickupPoint->getPickupPointId()) {
                        $actionUrl = $this->backendUrl->getUrl('hop/order/send', [
                            'order_id' => $orderId,
                            'form_key' => $subject->getFormKey()
                        ]);
                        $buttonList->add(
                            'enviar_a_hop',
                            [
                                'label'     => __('Enviar a HOP'),
                                'onclick' => 'sendToHopAction.confirmAndExecute("' . $actionUrl . '", ' . $orderId . ')',
                                'class'     => 'primary hop-shipment-button'
                            ]
                        );
                    }

                }
            }
        }

        return $buttonList;
    }
}
