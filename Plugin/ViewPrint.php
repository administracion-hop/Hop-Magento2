<?php

namespace Hop\Envios\Plugin;

use Magento\Shipping\Block\Adminhtml\View;
use Magento\Backend\Model\UrlInterface;

class ViewPrint
{
        /**
     * @var UrlInterface
     */
    private $backendUrl;

    /**
     * Constructor
     *
     * @param UrlInterface $backendUrl
     */
    public function __construct(UrlInterface $backendUrl)
    {
        $this->backendUrl = $backendUrl;
    }

    /**
     * Modifica la URL de impresión
     *
     * @param View $subject
     * @param string $result
     * @return string
     */
    public function afterGetPrintUrl(View $subject, $result)
    {
        // Obtén el ID de la orden desde el envío actual
        $shipment = $subject->getShipment();
        if ($shipment) {
            $orderId = $shipment->getOrderId() ?? $subject->getRequest()->getParam('order_id');

            // Obtén la orden relacionada con el envío
            $order = $shipment->getOrder();

            if ($order) {
                // Obtén el método de envío
                $shippingMethod = $order->getShippingMethod();

                // Comprueba si el método de envío es "hop"
                if ($shippingMethod == 'hop_hop') {
                    $orderId = $order->getId();

                    // Construye la URL personalizada
                    return $this->backendUrl->getUrl('hop/label/descargar', ['order_id' => $orderId]);
                }
            }


        }

        // Devuelve la URL original si no se puede obtener el ID de la orden
        return $result;
    }
}
