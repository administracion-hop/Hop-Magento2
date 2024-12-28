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

            // Construye la URL personalizada
            return $this->backendUrl->getUrl('hop/label/descargar', ['order_id' => $orderId]);
        }

        // Devuelve la URL original si no se puede obtener el ID de la orden
        return $result;
    }
}
