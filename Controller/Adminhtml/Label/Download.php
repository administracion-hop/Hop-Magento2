<?php

declare(strict_types=1);

namespace Hop\Envios\Controller\Adminhtml\Label;

use Hop\Envios\Model\Label\LabelPdf;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

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
     * @var LabelPdf
     */
    private LabelPdf $labelPdf;

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
     * @param HttpRequest $request
     * @param ResultFactory $resultFactory
     * @param LabelPdf $labelPdf
     * @param LoggerInterface $logger
     * @param FileFactory $fileFactory
     * @param ManagerInterface $messageManager
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        HttpRequest $request,
        ResultFactory $resultFactory,
        LabelPdf $labelPdf,
        LoggerInterface $logger,
        FileFactory $fileFactory,
        ManagerInterface $messageManager,
        UrlInterface $url,
        AuthorizationInterface $authorization
    ) {
        $this->request = $request;
        $this->resultFactory = $resultFactory;
        $this->labelPdf = $labelPdf;
        $this->logger = $logger;
        $this->fileFactory = $fileFactory;
        $this->messageManager = $messageManager;
        $this->url = $url;
        $this->authorization = $authorization;
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

        $shipmentId = (int) $this->request->getParam('shipment_id');
        $orderId = (int) $this->request->getParam('order_id');

        if (!$shipmentId && !$orderId) {
            $this->messageManager->addErrorMessage(__('Order ID is required.'));
            return $this->createRedirectResponse();
        }

        try {
            if ($shipmentId) {
                $labelUrl = $this->labelPdf->getLabelUrlForShipment($shipmentId);
            } else {
                $labelUrl = (string)(current($this->labelPdf->getLabelUrls($orderId)) ?: '');
            }

            if (empty($labelUrl)) {
                $this->messageManager->addErrorMessage(
                    __('No se encontró una etiqueta de Hop para esta orden.')
                );
                return $this->createRedirectResponse();
            }

            $file = $this->labelPdf->fetch($labelUrl);
            $filename = $this->extractFilename($labelUrl);
            if ($file['contentType'] === 'application/pdf') {
                $filename = $this->ensurePdfExtension($filename);
            }

            return $this->fileFactory->create(
                $filename,
                $file['content'],
                DirectoryList::VAR_DIR,
                $file['contentType']
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Error downloading Hop label',
                [
                    'order_id' => $orderId,
                    'shipment_id' => $shipmentId,
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
