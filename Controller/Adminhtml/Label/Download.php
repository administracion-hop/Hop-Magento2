<?php

declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\Label;

use Hop\Envios\Model\HopEnviosRepository;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File;
use Zend_Pdf;
use Zend_Pdf_Image;
use Zend_Pdf_Page;

/**
 * Download Hop shipping label controller
 *
 * @version 1.0.0
 * @author Hop Envíos <https://hopenvios.com.ar>
 * @copyright Copyright (c) 2025 Hop Envíos
 */
class Download implements ActionInterface, HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     */
    public const ADMIN_RESOURCE = 'Hop_Envios::label_download';

    /**
     * @var HttpRequest
     */
    private HttpRequest $request;

    /**
     * @var ResultFactory
     */
    private ResultFactory $resultFactory;

    /**
     * @var HopEnviosRepository
     */
    private HopEnviosRepository $hopEnviosRepository;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var FileFactory
     */
    private FileFactory $fileFactory;

    /**
     * @var ManagerInterface
     */
    private ManagerInterface $messageManager;

    /**
     * @var UrlInterface
     */
    private UrlInterface $url;

    /**
     * @var AuthorizationInterface
     */
    private AuthorizationInterface $authorization;

    /**
     * @var Filesystem
     */
    private Filesystem $filesystem;

    /**
     * @var File
     */
    private File $fileDriver;

    /**
     * Download constructor.
     *
     * @param HttpRequest $request
     * @param ResultFactory $resultFactory
     * @param HopEnviosRepository $hopEnviosRepository
     * @param Curl $curl
     * @param LoggerInterface $logger
     * @param FileFactory $fileFactory
     * @param ManagerInterface $messageManager
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     * @param Filesystem $filesystem
     * @param File $fileDriver
     */
    public function __construct(
        HttpRequest $request,
        ResultFactory $resultFactory,
        HopEnviosRepository $hopEnviosRepository,
        Curl $curl,
        LoggerInterface $logger,
        FileFactory $fileFactory,
        ManagerInterface $messageManager,
        UrlInterface $url,
        AuthorizationInterface $authorization,
        Filesystem $filesystem,
        File $fileDriver
    ) {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->curl = $curl;
        $this->logger = $logger;
        $this->fileFactory = $fileFactory;
        $this->messageManager = $messageManager;
        $this->url = $url;
        $this->authorization = $authorization;
        $this->filesystem = $filesystem;
        $this->fileDriver = $fileDriver;
    }

    /**
     * Execute action to download Hop shipping label
     *
     * @return ResponseInterface|Redirect
     */
    public function execute()
    {
        if (!$this->authorization->isAllowed(self::ADMIN_RESOURCE)) {
            $this->messageManager->addErrorMessage(__('You are not authorized to access this resource.'));
            return $this->createRedirectResponse();
        }

        $orderId = (int) $this->request->getParam('order_id');

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Order ID is required.'));
            return $this->createRedirectResponse();
        }

        try {
            $labelUrl = $this->getLabelUrl($orderId);

            if (empty($labelUrl)) {
                $this->messageManager->addErrorMessage(
                    __('No se encontró una etiqueta de Hop para esta orden.')
                );
                return $this->createRedirectResponse();
            }

            $filename = $this->extractFilename($labelUrl);

            return $this->downloadFile($labelUrl, $filename);
        } catch (\Exception $e) {
            $this->logger->error(
                'Error downloading Hop label',
                [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            $this->messageManager->addErrorMessage(
                __('Se produjo un error al descargar la etiqueta de Hop. Por favor inténtelo nuevamente.')
            );
            return $this->createRedirectResponse();
        }
    }

    /**
     * Get label URL from Hop Envios record
     *
     * @param int $orderId
     * @return string
     * @throws \Exception
     */
    private function getLabelUrl(int $orderId): string
    {
        $hopEnvios = $this->hopEnviosRepository->getByOrderId($orderId);

        if (!$hopEnvios) {
            return '';
        }

        $infoHop = $hopEnvios->getInfoHop();
        $infoHopData = json_decode($infoHop, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning(
                'Failed to decode Hop info JSON',
                ['order_id' => $orderId, 'json_error' => json_last_error_msg()]
            );
            return '';
        }

        return $infoHopData['label_url'] ?? '';
    }

    /**
     * Extract filename from URL
     *
     * @param string $url
     * @return string
     */
    private function extractFilename(string $url): string
    {
        $parts = explode('/', $url);
        $filename = end($parts);
        return trim($filename, '-') ?: 'hop-label.pdf';
    }

    /**
     * Download file using FileFactory
     *
     * @param string $url
     * @param string $filename
     * @return ResponseInterface
     * @throws \Exception
     */
    private function downloadFile(string $url, string $filename): ResponseInterface
    {
        $this->curl->get($url);
        $fileContent = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        if ($statusCode !== 200 || empty($fileContent)) {
            throw new \Exception(
                sprintf('Failed to download file from URL: %s (HTTP Status: %d)', $url, $statusCode)
            );
        }

        $contentType = $this->detectContentType($fileContent);
        $originalFileContent = $fileContent;

        if ($this->isImageType($contentType)) {
            try {
                $fileContent = $this->convertImageToPdf($fileContent);
                $filename = $this->ensurePdfExtension($filename);
                $contentType = 'application/pdf';
            } catch (\Exception $e) {
                $this->logger->warning(
                    'Failed to convert image to PDF, downloading original file instead',
                    [
                        'filename' => $filename,
                        'error' => $e->getMessage()
                    ]
                );
                $fileContent = $originalFileContent;
            }
        }

        return $this->fileFactory->create(
            $filename,
            $fileContent,
            DirectoryList::VAR_DIR,
            $contentType
        );
    }

    /**
     * Detect content type from file content
     *
     * @param string $content
     * @return string
     */
    private function detectContentType(string $content): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->buffer($content);
    }

    /**
     * Check if content type is an image
     *
     * @param string $contentType
     * @return bool
     */
    private function isImageType(string $contentType): bool
    {
        return in_array($contentType, [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/gif',
            'image/webp'
        ]);
    }

    /**
     * Convert image to PDF
     *
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

            $pdfContent = $this->createPdfWithZend($tmpJpegPath, $width, $height);

            return $pdfContent;
        } catch (\Exception $e) {
            $this->logger->error('Error converting image to PDF: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
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
     * Convert image to JPEG format
     *
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
                if (function_exists('imagecreatefromwebp')) {
                    $image = imagecreatefromwebp($sourcePath);
                } else {
                    throw new \Exception('WebP support is not available');
                }
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
     * Create PDF using Zend_Pdf
     *
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

        $image = Zend_Pdf_Image::imageWithPath($imagePath);

        $page->drawImage($image, 0, 0, $pageWidth, $pageHeight);

        $pdf->pages[] = $page;

        return $pdf->render();
    }

    /**
     * Ensure filename has PDF extension
     *
     * @param string $filename
     * @return string
     */
    private function ensurePdfExtension(string $filename): string
    {
        $pathInfo = pathinfo($filename);
        $extension = strtolower($pathInfo['extension'] ?? '');

        if ($extension !== 'pdf') {
            $basename = $pathInfo['filename'] ?? 'hop-label';
            return $basename . '.pdf';
        }

        return $filename;
    }

    /**
     * Create redirect response to referer URL
     *
     * @return Redirect
     */
    private function createRedirectResponse(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $refererUrl = $this->request->getServer('HTTP_REFERER');
        if (!$refererUrl) {
            $refererUrl = $this->url->getUrl('admin/dashboard');
        }

        $resultRedirect->setUrl($refererUrl);

        return $resultRedirect;
    }
}


