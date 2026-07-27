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
    /**
     * @var HopHelper
     */
    protected $_helper;

    /**
     * @var Filesystem
     */
    protected $_filesystem;

    /**
     * @var View
     */
    protected $_shipment;

    /**
     * @var Webservice
     */
    protected $_webservice;

    /**
     * @param Filesystem $filesystem
     * @param View $shipment
     * @param HopHelper $hopHelper
     * @param Webservice $webservice
     *
     * @todo Refactor to use Magento's built-in HTTP client for better integration and error handling.
     */
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
        $extension = pathinfo($imageString, PATHINFO_EXTENSION);
        if (!empty($extension) && !in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif'])) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Formato de imagen no soportado: %1', $extension)
            );
        }
        $shipment = $this->_shipment->getShipment();

        if ($shipment && $shipment->getOrder()) {
            $order = $shipment->getOrder();
            $shippingMethod = $order->getShippingMethod();

            $this->_helper->log('[DEBUG][createPdfPageFromImageString] ENTRY orderId=' . $order->getId() . ' shippingMethod=' . $shippingMethod . ' imageString=' . $imageString);

            if ($shippingMethod === 'hop_hop') {
                $url = $imageString;

                if (!empty($url)) {
                    $mediapath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath() . 'Hop/';
                    if (!file_exists($mediapath) || !is_dir($mediapath)) {
                        mkdir($mediapath, 0775, true);
                    }

                    $filename = basename($url);
                    $filePath = $mediapath . $filename;

                    $this->_helper->log('[DEBUG][createPdfPageFromImageString] url=' . $url . ' filename=' . $filename . ' extension=' . pathinfo($filename, PATHINFO_EXTENSION) . ' filePath=' . $filePath);

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

                        $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                        $curlContentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                        $curlErrno = curl_errno($curl);
                        $curlError = curl_error($curl);

                        curl_close($curl);

                        $this->_helper->log('[DEBUG][createPdfPageFromImageString] curl httpCode=' . $curlHttpCode . ' contentType=' . $curlContentType . ' errno=' . $curlErrno . ' error=' . $curlError . ' bytesDownloaded=' . ($imageData !== false ? strlen($imageData) : 'false') . ' first16bytesHex=' . ($imageData !== false ? bin2hex(substr($imageData, 0, 16)) : 'n/a'));

                        if ($imageData === false) {
                            $this->_helper->log('No se pudo descargar la imagen desde la URL: ' . $url, true);
                        }

                        // Normalize CMYK→RGB via GD so Zend_Pdf_Image can embed the JPEG.
                        if ($imageData && function_exists('imagecreatefromstring')) {
                            $img = @imagecreatefromstring($imageData);
                            $this->_helper->log('[DEBUG][createPdfPageFromImageString] imagecreatefromstring success=' . ($img !== false ? '1' : '0') . ' gdLastError=' . json_encode(error_get_last()));
                            if ($img !== false) {
                                ob_start();
                                imagejpeg($img, null, 95);
                                $normalized = ob_get_clean();
                                imagedestroy($img);
                                $this->_helper->log('[DEBUG][createPdfPageFromImageString] normalized bytes=' . ($normalized ? strlen($normalized) : '0') . ' willReplace=' . (($normalized && strlen($normalized) > 100) ? '1' : '0'));
                                if ($normalized && strlen($normalized) > 100) {
                                    $imageData = $normalized;
                                }
                            }
                        } else {
                            $this->_helper->log('[DEBUG][createPdfPageFromImageString] SKIPPED GD normalization: imageData empty or imagecreatefromstring missing');
                        }

                        file_put_contents($filePath, $imageData);

                        if (!file_exists($filePath)) {
                            $this->_helper->log('No se pudo guardar la imagen desde la URL: ' . $url, true);
                        }

                        $finalSize = file_exists($filePath) ? filesize($filePath) : 'n/a';
                        $gis = @getimagesize($filePath);
                        $this->_helper->log('[DEBUG][createPdfPageFromImageString] savedFilePath=' . $filePath . ' fileSize=' . $finalSize . ' getimagesize=' . json_encode($gis));

                        list($width, $height) = getimagesize($filePath);

                        $pdfPage = new Zend_Pdf_Page($width, $height);
                        $image = Zend_Pdf_Image::imageWithPath($filePath);
                        $pdfPage->drawImage($image, 0, 0, $width, $height);

                        $this->_helper->log('[DEBUG][createPdfPageFromImageString] SUCCESS width=' . $width . ' height=' . $height);

                        return $pdfPage;

                    } catch (\Exception $e) {
                        $this->_helper->log('Error al procesar la etiqueta PDF: ' . $e->getMessage() . ' trace=' . $e->getTraceAsString(), true);
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

    /**
     * Convert image URLs to PDFs before combining labels.
     * @param \Magento\Shipping\Model\Shipping\LabelGenerator $subject
     * @param array $labelsContent
     * @return array
     *
     * @todo Refactor to use Magento's built-in HTTP client for better integration and error handling.
     */
    public function beforeCombineLabelsPdf(
        \Magento\Shipping\Model\Shipping\LabelGenerator $subject,
        array $labelsContent = []
    ) {
        $mediaPath = BP . '/pub/media/Hop/';

        if (!file_exists($mediaPath) || !is_dir($mediaPath)) {
            mkdir($mediaPath, 0775, true);
        }

        $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] ENTRY labelsCount=' . count($labelsContent) . ' contents=' . json_encode(array_map(function ($c) {
            return is_string($c) ? (strlen($c) > 200 ? substr($c, 0, 200) . '...(' . strlen($c) . ' bytes)' : $c) : gettype($c);
        }, $labelsContent)));

        foreach ($labelsContent as &$content) {
            if (filter_var($content, FILTER_VALIDATE_URL)) {
                $url = $content;
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $filePath = $mediaPath . $filename;

                $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] url=' . $url . ' filename=' . $filename . ' extension=' . pathinfo($filename, PATHINFO_EXTENSION) . ' filePath=' . $filePath);

                try {
                    $curl = curl_init();
                    curl_setopt_array($curl, [
                        CURLOPT_URL            => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_TIMEOUT        => 10
                    ]);
                    $imageData = curl_exec($curl);

                    $curlHttpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                    $curlContentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
                    $curlErrno = curl_errno($curl);
                    $curlError = curl_error($curl);

                    curl_close($curl);

                    $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] curl httpCode=' . $curlHttpCode . ' contentType=' . $curlContentType . ' errno=' . $curlErrno . ' error=' . $curlError . ' bytesDownloaded=' . ($imageData !== false ? strlen($imageData) : 'false') . ' first16bytesHex=' . ($imageData !== false ? bin2hex(substr($imageData, 0, 16)) : 'n/a'));

                    if ($imageData === false) {
                        $this->_helper->log(__('Error descargando imagen desde: ') . $url, true);
                        continue;
                    }

                    // Normalize CMYK→RGB via GD so Zend_Pdf_Image can embed the JPEG.
                    if (function_exists('imagecreatefromstring')) {
                        $img = @imagecreatefromstring($imageData);
                        $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] imagecreatefromstring success=' . ($img !== false ? '1' : '0'));
                        if ($img !== false) {
                            ob_start();
                            imagejpeg($img, null, 95);
                            $normalized = ob_get_clean();
                            imagedestroy($img);
                            $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] normalized bytes=' . ($normalized ? strlen($normalized) : '0') . ' willReplace=' . (($normalized && strlen($normalized) > 100) ? '1' : '0'));
                            if ($normalized && strlen($normalized) > 100) {
                                $imageData = $normalized;
                            }
                        }
                    }

                    file_put_contents($filePath, $imageData);

                    if (!file_exists($filePath)) {
                        $this->_helper->log(__('No se pudo guardar la imagen en: ') . $filePath, true);
                        continue;
                    }

                    $finalSize = file_exists($filePath) ? filesize($filePath) : 'n/a';
                    $gis = @getimagesize($filePath);
                    $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] savedFilePath=' . $filePath . ' fileSize=' . $finalSize . ' getimagesize=' . json_encode($gis));

                    list($width, $height) = getimagesize($filePath);

                    $pdf = new \Zend_Pdf();
                    $pdfPage = new \Zend_Pdf_Page($width, $height);
                    $image = \Zend_Pdf_Image::imageWithPath($filePath);
                    $pdfPage->drawImage($image, 0, 0, $width, $height);
                    $pdf->pages[] = $pdfPage;
                    $pdfBinary = $pdf->render();
                    $content = $pdfBinary;

                    $this->_helper->log('[DEBUG][beforeCombineLabelsPdf] SUCCESS width=' . $width . ' height=' . $height . ' pdfBytes=' . strlen($pdfBinary));

                } catch (\Exception $e) {
                    $this->_helper->log(__('Error procesando la imagen: ') . $e->getMessage() . ' trace=' . $e->getTraceAsString(), true);
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
