<?php

namespace Hop\Envios\Plugin\Shipping;

use Magento\Framework\App\Filesystem\DirectoryList;
use Zend_Pdf_Image;
use Zend_Pdf_Page;
use Zend_Pdf;
use Magento\Framework\Filesystem;
use Magento\Shipping\Block\Adminhtml\View;
use Hop\Envios\Helper\Data as HopHelper;
use Hop\Envios\Model\Webservice;

class LabelGeneratorPlugin
{
    protected $_helper;
    protected $_filesystem;
    protected $_shipment;

    /**
     * @var Webservice
     */
    protected $_webservice;

    public function __construct(
        Filesystem $filesystem,
        View $shipment,
        HopHelper $hopHelper,
        Webservice $webservice
    ) {
        $this->_filesystem = $filesystem;
        $this->_shipment = $shipment;
        $this->_helper = $hopHelper;
        $this->_webservice = $webservice;
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

                    $lastToken = $this->_webservice->getLastToken();
                    $_accessToken = $lastToken->getAccessToken();

                    try {
                        $curl = curl_init();

                        curl_setopt_array($curl, array(
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'GET',
                        ));

                        $imageData = curl_exec($curl);

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

                        return $pdfPage;

                    } catch (\Exception $e) {
                        $this->_helper->log('Error al procesar la etiqueta PDF: ' . $e->getMessage(), true);
                        throw new \Magento\Framework\Exception\LocalizedException(
                            __('Error al generar la etiqueta de envío: %1', $e->getMessage())
                        );
                    } finally {
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

    public function beforeCombineLabelsPdf(
        \Magento\Shipping\Model\Shipping\LabelGenerator $subject,
        array $labelsContent = []
    ) {
        $logPath = BP . '/var/log/yeah.log';
        $mediaPath = BP . '/pub/media/Hop/';

        if (!file_exists($mediaPath) || !is_dir($mediaPath)) {
            mkdir($mediaPath, 0775, true);
        }

        foreach ($labelsContent as &$content) {
            if (filter_var($content, FILTER_VALIDATE_URL)) {
                $url = $content;
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $filePath = $mediaPath . $filename;

                try {
                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_URL            => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 10
                    ]);
                    $imageData = curl_exec($curl);
                    curl_close($curl);

                    if ($imageData === false) {
                        file_put_contents($logPath, "Error descargando imagen desde: $url" . PHP_EOL, FILE_APPEND);
                        continue;
                    }

                    file_put_contents($filePath, $imageData);

                    if (!file_exists($filePath)) {
                        file_put_contents($logPath, "No se pudo guardar la imagen en: $filePath" . PHP_EOL, FILE_APPEND);
                        continue;
                    }

                    list($width, $height) = getimagesize($filePath);

                    $pdf = new \Zend_Pdf();
                    $pdfPage = new \Zend_Pdf_Page($width, $height);
                    $image = \Zend_Pdf_Image::imageWithPath($filePath);
                    $pdfPage->drawImage($image, 0, 0, $width, $height);
                    $pdf->pages[] = $pdfPage;

                    // Convertimos el PDF en string binario
                    $pdfBinary = $pdf->render();

                    // Reemplazamos la URL con el contenido binario del PDF
                    $content = $pdfBinary;

                } catch (\Exception $e) {
                    file_put_contents($logPath, "Error procesando la imagen: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
                    continue;
                } finally {
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }

        return [$labelsContent];
    }

}
