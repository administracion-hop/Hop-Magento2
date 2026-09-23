<?php
namespace Hop\Envios\Plugin\Widget;

use Magento\Backend\Block\Widget\Context AS Subject;
use Magento\Sales\Model\Order;
use Hop\Envios\Helper\Data as DataHop;
use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Magento\Framework\UrlInterface;
use Hop\Envios\Model\OrderPickupPointRepository;

/**
 * Class Context
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
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
     * @var HopEnviosShipmentRepository
     */
    protected $hopEnviosShipmentRepository;

    /**
     * @var OrderPickupPointRepository
     */
    protected $orderPickupPointRepository;

    /**
     * Context constructor.
     * @param Order $order
     * @param DataHop $helperHop
     * @param UrlInterface $urlInterface,
     * @param HopEnviosRepository $hopEnviosRepository
     * @param HopEnviosShipmentRepository $hopEnviosShipmentRepository
     * @param OrderPickupPointRepository $orderPickupPointRepository
     */
    public function __construct(
        Order $order,
        DataHop $helperHop,
        UrlInterface $urlInterface,
        HopEnviosRepository $hopEnviosRepository,
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        OrderPickupPointRepository $orderPickupPointRepository
    )
    {
        $this->order = $order;
        $this->helperHop = $helperHop;
        $this->backendUrl = $urlInterface;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->orderPickupPointRepository = $orderPickupPointRepository;
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
            $orderId    = (int)$subject->getRequest()->getParam('order_id');
            $order      = $this->order->load($orderId);
            if ($order->getShippingMethod() === 'hop_hop')
            {
                $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);
                // Multibulto orders (2+ shipments) never populate the order-level info_hop
                // field — each shipment's tracking/label lives in its own hop_envios_shipment
                // row instead. status_shipment is the only order-level signal that's reliable
                // for both the single-shipment and multibulto paths.
                $isDispatched = $hopEnvios && $hopEnvios->getStatusShipment() === 'completed';

                if ($isDispatched) {
                    $records = $this->hopEnviosShipmentRepository->getByHopEnvioId((int)$hopEnvios->getEntityId());

                    if (count($records) > 1) {
                        // Multibulto: several labels, no single one to put on the toolbar —
                        // open the picker modal instead of stacking a button per shipment.
                        $labelsUrl = $this->backendUrl->getUrl('hop/order/labels');
                        $buttonList->add(
                            'etiquetas_hop',
                            [
                                'label'     => __('Etiquetas HOP'),
                                'onclick' => "hopLabelsView.open('". $labelsUrl."', ".$orderId.")",
                                'class'     => 'primary hop-shipment-button'
                            ]
                        );
                    } else {
                        // Single shipment: same one-off download/status buttons as before,
                        // sourced from the per-shipment record if present, else the legacy
                        // order-level info_hop (pre-hop_envios_shipment orders).
                        if (!empty($records)) {
                            $labelUrl = $records[0]->getLabelUrl();
                            $trackingNro = $records[0]->getTrackingNro();
                            $downloadParams = ['shipment_id' => (int)$records[0]->getShipmentId()];
                        } else {
                            $infoHop = json_decode($hopEnvios->getInfoHop() ?? '');
                            $labelUrl = $infoHop->label_url ?? '';
                            $trackingNro = $infoHop->tracking_nro ?? '';
                            $downloadParams = ['order_id' => $orderId];
                        }

                        $baseUrl = '';
                        if (!empty($labelUrl)) {
                            if (substr_compare($labelUrl, '.zpl', -4) === 0) {
                                $baseUrl = str_ireplace('http://', 'https://', $labelUrl);
                            } else {
                                $baseUrl = $this->backendUrl->getUrl('hop/label/download', $downloadParams);
                            }
                        }

                        if (!empty($baseUrl)) {
                            $buttonList->add(
                                'descargar_etiqueta_hop',
                                [
                                    'label'     => __('Descargar etiqueta HOP'),
                                    'onclick' => "setLocation('{$baseUrl}')",
                                    'class'     => 'primary hop-shipment-button'
                                ]
                            );
                            if (!empty($trackingNro)) {
                                $trackingUrl = 'https://hopenvios.com.ar/segui-tu-envio?c=' . $trackingNro;
                                $buttonList->add(
                                    'estado_hop',
                                    [
                                        'label'     => __('Estado HOP'),
                                        'onclick' => "window.open('".$trackingUrl."', '_blank')",
                                        'class'     => 'primary hop-shipment-button'
                                    ]
                                );
                            }
                        }
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
                    $selectedPickupPoint = $this->orderPickupPointRepository->getByOrderId((int)$orderId);
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
