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
        AuthorizationInterface $authorization
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
        // Get file content from remote URL
        $this->curl->get($url);
        $fileContent = $this->curl->getBody();
        $statusCode = $this->curl->getStatus();

        if ($statusCode !== 200 || empty($fileContent)) {
            throw new \Exception(
                sprintf('Failed to download file from URL: %s (HTTP Status: %d)', $url, $statusCode)
            );
        }

        // Use FileFactory to create proper download response
        return $this->fileFactory->create(
            $filename,
            $fileContent,
            DirectoryList::VAR_DIR,
            'application/pdf'
        );
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

        // Get referer URL or fallback to admin dashboard
        $refererUrl = $this->request->getServer('HTTP_REFERER');
        if (!$refererUrl) {
            $refererUrl = $this->url->getUrl('admin/dashboard');
        }

        $resultRedirect->setUrl($refererUrl);

        return $resultRedirect;
    }
}


