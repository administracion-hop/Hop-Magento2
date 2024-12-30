<?php

namespace Hop\Envios\Plugin\Shipping;

use Magento\Framework\App\Filesystem\DirectoryList;
use Zend_Pdf_Image;
use Zend_Pdf_Page;
use Zend_Pdf;
use Magento\Framework\Filesystem;
use Magento\Shipping\Block\Adminhtml\View;
use Hop\Envios\Helper\Data as HopHelper;


class CustomLabelGeneratorPlugin
{

    /**
     * @var HopHelper
     */
    protected $_helper;

    /**
     * @var Filesystem
     */
    protected $_filesystem;

    /**
     * @var View;
     */
    protected $_shipment;

    /**
     * Hop constructor.
     * @param View $shipment
     * @param Filesystem $filesystem
     * @param HopHelper $hopHelper
     */
    public function __construct(
        Filesystem $filesystem,
        View $shipment,
        HopHelper $hopHelper,
    ) {
        $this->_filesystem = $filesystem;
        $this->_shipment = $shipment;
        $this->_helper = $hopHelper;
    }


    /**
     * Intercepta el método createPdfPageFromImageString para personalizar el contenido del PDF.
     *
     * @param \Magento\Shipping\Model\Shipping\LabelGenerator $subject
     * @param \Zend_Pdf_Page $result
     * @param string $imageString
     * @return \Zend_Pdf_Page
     * @throws \Exception
     */
    public function aroundCreatePdfPageFromImageString(
        \Magento\Shipping\Model\Shipping\LabelGenerator $subject,
        \Closure $proceed,
        $imageString
    ) {

        $shipment = $this->_shipment->getShipment();

        if ( $shipment && $shipment->getOrder() ) {
        
            $order = $shipment->getOrder();
            $shippingMethod = $order->getShippingMethod();

            // Encapsula logica solo para el metodo de envio hop
            if ($shippingMethod == 'hop_hop') {
               
                $url = $imageString;

                if (!empty($url)) {

                    // Definir la ruta en el directorio MEDIA
                    $mediapath = $this->_filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';
                    if (!file_exists($mediapath) || !is_dir($mediapath)) {
                        mkdir($mediapath, 0775, true);
                    }
                    $filename = basename($url);
                    $filePath = $mediapath . $filename;
        
                    // Solicitud curl
                    $curl = curl_init($url);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                    $imageData = curl_exec($curl);
                    curl_close($curl);
        
                    // guardar y valida si la imagen fue guarda
                    file_put_contents($filePath, $imageData);
                    if (!file_exists($filePath)) {
                        $this->_helper->log('No se pudo guardar la imagen desde la URL: ' . $url, true);
                    }
        
                    // Imagen
                    list($width, $height) = getimagesize($filePath);
                    $pdfPage = new Zend_Pdf_Page($width, $height);
                    $image = Zend_Pdf_Image::imageWithPath($filePath);
                    $pdfPage->drawImage($image, 0, 0, $width, $height);
                    unlink($filePath);
        
                    return $pdfPage;
                } 
            } else {
                return $proceed($imageString);
            }

        }

    }
}
