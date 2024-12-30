<?php

namespace Hop\Envios\Plugin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Zend_Pdf_Image;
use Zend_Pdf_Page;
use Zend_Pdf;
use Magento\Framework\Filesystem;
use Magento\Shipping\Block\Adminhtml\View;


class CustomLabelGeneratorPlugin
{

    protected $_filesystem;  // Declarar la propiedad $_filesystem
    protected $_shipment;  // Declarar la propiedad $_filesystem

    public function __construct(
        Filesystem $filesystem,  // Inyectar la dependencia Filesystem
        View $shipment
    ) {
        $this->_filesystem = $filesystem;  // Inicializar la propiedad $_filesystem
        $this->_shipment = $shipment;  // Inicializar la propiedad $_filesystem
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
        
            // Obtén la orden relacionada con el envío
            $order = $shipment->getOrder();

            // Obtén el método de envío
            $shippingMethod = $order->getShippingMethod();

            // Comprueba si el método de envío es "hop"
            if ($shippingMethod == 'hop_hop') {
                // URL de la imagen (puede ser parte de $imageString o proporcionada de forma independiente)
                $url = $imageString;

                // Verificar que la URL no esté vacía
                if (!empty($url)) {

                    // Definir la ruta en el directorio MEDIA
                    $mediapath = $this->_filesystem->getDirectoryRead(\Magento\Framework\App\Filesystem\DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';

                    // Crear el directorio si no existe
                    if (!file_exists($mediapath) || !is_dir($mediapath)) {
                        mkdir($mediapath, 0775, true);
                    }
        
                    // Nombre del archivo (se puede extraer de la URL o establecer uno fijo)
                    $filename = basename($url);
                    $filePath = $mediapath . $filename;
        
                    // Descargar la imagen usando CURL
                    $curl = curl_init($url);
                    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                    $imageData = curl_exec($curl);
                    curl_close($curl);
        
                    // Guardar la imagen descargada en el directorio
                    file_put_contents($filePath, $imageData);
        
                    // Verificar si la imagen se guardó correctamente
                    if (!file_exists($filePath)) {
                        throw new \Exception("No se pudo guardar la imagen desde la URL: " . $url);
                    }
        
                    // Obtener las dimensiones de la imagen
                    list($width, $height) = getimagesize($filePath);
        
                    // Crear una nueva página del PDF con las dimensiones de la imagen
                    $pdfPage = new Zend_Pdf_Page($width, $height);
        
                    // Agregar la imagen al PDF
                    $image = Zend_Pdf_Image::imageWithPath($filePath);
                    $pdfPage->drawImage($image, 0, 0, $width, $height);
        
                    // Eliminar la imagen descargada después de usarla
                    unlink($filePath);
        
                    return $pdfPage;
                } 
            } else {
                // Si no hay URL válida, seguir el flujo original
                return $proceed($imageString);
            }

        }

    }
}
