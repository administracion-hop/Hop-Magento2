<?php

declare(strict_types=1);

namespace Hop\Envios\Model\Label;

use Hop\Envios\Model\HopEnviosRepository;
use Hop\Envios\Model\HopEnviosShipmentRepository;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Zend_Pdf;
use Zend_Pdf_Image;
use Zend_Pdf_Page;

/**
 * Etiquetas Hop: dónde están, cómo bajarlas y cómo unirlas en un solo PDF.
 *
 * Lo usan la descarga individual (Controller/Adminhtml/Label/Download) y la acción masiva
 * (Controller/Adminhtml/Order/MassLabels), para que las dos resuelvan la etiqueta igual.
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 * @package Hop\Envios\Model\Label
 */
class LabelPdf
{
    /**
     * @var HopEnviosRepository
     */
    private HopEnviosRepository $hopEnviosRepository;

    /**
     * @var HopEnviosShipmentRepository
     */
    private HopEnviosShipmentRepository $hopEnviosShipmentRepository;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var File
     */
    private File $fileDriver;

    /**
     * @param HopEnviosRepository $hopEnviosRepository
     * @param HopEnviosShipmentRepository $hopEnviosShipmentRepository
     * @param Curl $curl
     * @param LoggerInterface $logger
     * @param Filesystem $filesystem
     * @param File $fileDriver
     */
    public function __construct(
        HopEnviosRepository $hopEnviosRepository,
        HopEnviosShipmentRepository $hopEnviosShipmentRepository,
        Curl $curl,
        LoggerInterface $logger,
        Filesystem $filesystem,
        File $fileDriver
    ) {
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->hopEnviosShipmentRepository = $hopEnviosShipmentRepository;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Todas las etiquetas de un pedido: las de cada bulto si es multibulto, si no la del
     * info_hop a nivel pedido (órdenes previas a hop_envios_shipment).
     *
     * @param int $orderId
     * @return string[] URLs, en orden de bulto
     */
    public function getLabelUrls(int $orderId): array
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);
        if (!$hopEnvios) {
            return [];
        }

        $urls = [];
        foreach ($this->hopEnviosShipmentRepository->getByHopEnvioId((int)$hopEnvios->getEntityId()) as $record) {
            $url = (string)($record->getLabelUrl() ?? '');
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        if (!$urls) {
            $infoHop = json_decode((string)$hopEnvios->getInfoHop(), true);
            $url = is_array($infoHop) ? (string)($infoHop['label_url'] ?? '') : '';
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Etiqueta de un envío puntual (multibulto).
     *
     * @param int $shipmentId
     * @return string
     */
    public function getLabelUrlForShipment(int $shipmentId): string
    {
        $record = $this->hopEnviosShipmentRepository->getByShipmentId($shipmentId);
        return $record ? (string)($record->getLabelUrl() ?? '') : '';
    }

    /**
     * Baja la etiqueta tal cual está publicada.
     *
     * @param string $url
     * @return array{content: string, contentType: string}
     * @throws \Exception
     */
    public function fetch(string $url): array
    {
        $this->curl->get($url);
        $content = $this->curl->getBody();
        $status = $this->curl->getStatus();

        if ($status !== 200 || empty($content)) {
            throw new \Exception(
                sprintf('Failed to download file from URL: %s (HTTP Status: %d)', $url, $status)
            );
        }

        $contentType = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if ($this->isImageType($contentType)) {
            try {
                return ['content' => $this->convertImageToPdf($content), 'contentType' => 'application/pdf'];
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Failed to convert image to PDF, using original file instead',
                    ['url' => $url, 'error' => $e->getMessage()]
                );
            }
        }

        return ['content' => $content, 'contentType' => $contentType];
    }

    /**
     * Baja la etiqueta y la devuelve siempre como PDF. Lo que no sea PDF ni imagen (ZPL,
     * por ejemplo) no se puede meter en un PDF unificado: tira excepción y el caller informa.
     *
     * @param string $url
     * @return string Contenido PDF
     * @throws \Exception
     */
    public function fetchAsPdf(string $url): string
    {
        $file = $this->fetch($url);

        if ($file['contentType'] !== 'application/pdf') {
            throw new \Exception(
                sprintf('La etiqueta no es un PDF ni una imagen (%s): %s', $file['contentType'], $url)
            );
        }

        return $file['content'];
    }

    /**
     * Une varios PDFs en uno solo, respetando el orden recibido.
     *
     * @param string[] $pdfContents
     * @return string
     * @throws \Exception
     */
    public function merge(array $pdfContents): string
    {
        if (count($pdfContents) === 1) {
            return reset($pdfContents);
        }

        $merged = new Zend_Pdf();
        foreach ($pdfContents as $content) {
            $source = Zend_Pdf::parse($content);
            foreach ($source->pages as $page) {
                $merged->pages[] = clone $page;
            }
        }

        return $merged->render();
    }

    /**
     * @param string $contentType
     * @return bool
     */
    private function isImageType(string $contentType): bool
    {
        return in_array($contentType, ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /**
     * @param string $imageContent
     * @return string PDF content
     * @throws \Exception
     */
    private function convertImageToPdf(string $imageContent): string
    {
        $tmpImagePath = null;
        $tmpJpegPath = null;

        try {
            $tmpDir = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
            $tmpImagePath = $tmpDir->getAbsolutePath('hop_label_' . uniqid() . '.tmp');

            $this->fileDriver->filePutContents($tmpImagePath, $imageContent);

            $imageInfo = getimagesize($tmpImagePath);
            if ($imageInfo === false) {
                throw new \Exception('Invalid image format');
            }

            list($width, $height, $imageType) = $imageInfo;

            $tmpJpegPath = $tmpDir->getAbsolutePath('hop_label_' . uniqid() . '.jpg');
            $this->convertToJpeg($tmpImagePath, $tmpJpegPath, $imageType);

            return $this->createPdfWithZend($tmpJpegPath, $width, $height);
        } catch (\Exception $e) {
            $this->logger->error('Error converting image to PDF: ' . $e->getMessage());
            throw $e;
        } finally {
            if ($tmpImagePath && $this->fileDriver->isExists($tmpImagePath)) {
                $this->fileDriver->deleteFile($tmpImagePath);
            }
            if ($tmpJpegPath && $this->fileDriver->isExists($tmpJpegPath)) {
                $this->fileDriver->deleteFile($tmpJpegPath);
            }
        }
    }

    /**
     * @param string $sourcePath
     * @param string $destPath
     * @param int $imageType
     * @return void
     * @throws \Exception
     */
    private function convertToJpeg(string $sourcePath, string $destPath, int $imageType): void
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagecreatefromwebp')) {
                    throw new \Exception('WebP support is not available');
                }
                $image = imagecreatefromwebp($sourcePath);
                break;
            default:
                throw new \Exception('Unsupported image type: ' . $imageType);
        }

        if ($image === false) {
            throw new \Exception('Failed to create image resource from file');
        }

        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            $width = imagesx($image);
            $height = imagesy($image);
            $background = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($background, 255, 255, 255);
            imagefill($background, 0, 0, $white);
            imagecopy($background, $image, 0, 0, 0, 0, $width, $height);
            imagedestroy($image);
            $image = $background;
        }

        $result = imagejpeg($image, $destPath, 95);
        imagedestroy($image);

        if (!$result) {
            throw new \Exception('Failed to save image as JPEG');
        }
    }

    /**
     * @param string $imagePath
     * @param int $width
     * @param int $height
     * @return string
     * @throws \Exception
     */
    private function createPdfWithZend(string $imagePath, int $width, int $height): string
    {
        $pdf = new Zend_Pdf();

        $pageWidth = ($width / 96) * 72;
        $pageHeight = ($height / 96) * 72;

        $page = new Zend_Pdf_Page($pageWidth, $pageHeight);
        $page->drawImage(Zend_Pdf_Image::imageWithPath($imagePath), 0, 0, $pageWidth, $pageHeight);
        $pdf->pages[] = $page;

        return $pdf->render();
    }
}
