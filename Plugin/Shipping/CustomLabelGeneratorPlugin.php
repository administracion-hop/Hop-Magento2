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
    protected $_helper;
    protected $_filesystem;
    protected $_shipment;

    public function __construct(
        Filesystem $filesystem,
        View $shipment,
        HopHelper $hopHelper
    ) {
        $this->_filesystem = $filesystem;
        $this->_shipment = $shipment;
        $this->_helper = $hopHelper;
    }

    public function aroundCreatePdfPageFromImageString(
        \Magento\Shipping\Model\Shipping\LabelGenerator $subject,
        \Closure $proceed,
        $imageString
    ) {
        $shipment = $this->_shipment->getShipment();

        if ($shipment && $shipment->getOrder()) {
            $order = $shipment->getOrder();
            $shippingMethod = $order->getShippingMethod();

            if ($shippingMethod == 'hop_hop') {
                $url = $imageString;

                if (!empty($url)) {
                    $mediapath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';
                    if (!file_exists($mediapath) || !is_dir($mediapath)) {
                        mkdir($mediapath, 0775, true);
                    }

                    $filename = basename($url);
                    $filePath = $mediapath . $filename;

                    try {
                        $curl = curl_init($url);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);  
                        /*$filesize =  curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);    
                        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);                 
                        $imageData = curl_exec($curl);*/

                        $attempts = 0;
                        $maxRetries = 5; // Número máximo de intentos de descarga
                        $imageData = false;
                        $httpCode = 0;

                        // Intentar descargar la imagen hasta que sea exitosa
                        do {
                            $imageData = curl_exec($curl);
                            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                            if ($imageData !== false && $httpCode == 200) {
                                break;
                            }

                            $attempts++;
                            sleep(2);
                        } while ($attempts < $maxRetries);

                        curl_close($curl);

                        if ($imageData === false) {
                            $this->_helper->log('No se pudo descargar la imagen desde la URL: ' . $url, true);
                        }
                        file_put_contents($filePath, $imageData);
                        if (!file_exists($filePath)) {
                            $this->_helper->log('No se pudo guardar la imagen desde la URL: ' . $url, true);
                        }

                        list($width, $height) = getimagesize($filePath);

                        $pdfPage = new Zend_Pdf_Page($width, $height);
                        $image = Zend_Pdf_Image::imageWithPath($filePath);
                        $pdfPage->drawImage($image, 0, 0, $width, $height);

                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }

                        return $pdfPage;
                    } catch (\Exception $e) {
                        $this->_helper->log('Error al procesar la etiqueta PDF: ' . $e->getMessage(), true);
                        throw new \Magento\Framework\Exception\LocalizedException(
                            __('Error al generar la etiqueta de envío: %1', $e->getMessage())
                        );
                    } finally {
                        // Limpiar archivo temporal, si existe
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                } else {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('La URL de la imagen es inválida o está vacía.')
                    );
                }
            }
        }

        return $proceed($imageString);
    }
}
