<?php
namespace Hop\Envios\Model;

use Hop\Envios\Api\WebhookManagementInterface;
use Hop\Envios\Api\Data\WebhookResponseInterface;
use Hop\Envios\Api\Data\WebhookResponseInterfaceFactory;
use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Helper\StatusManager;
use Hop\Envios\Helper\Data as HelperData;

/**
 * Webhook management service
 */
class WebhookManagement implements WebhookManagementInterface
{
    /**
     * @var WebhookResponseInterfaceFactory
     */
    protected $responseFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var  \Magento\Framework\Webapi\Rest\Request;
     */
    protected $request;

    /**
     * @var StatusManager
     */
    protected $statusManager;

    /**
     * @var HelperData
     */
    protected $helperData;

    /**
     * @param WebhookResponseInterfaceFactory $responseFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        WebhookResponseInterfaceFactory $responseFactory,
        \Magento\Framework\Webapi\Rest\Request $request,
        StatusManager $statusManager,
        HelperData $helperData,
        LoggerInterface $logger
    ) {
        $this->responseFactory = $responseFactory;
        $this->logger = $logger;
        $this->request = $request;
        $this->statusManager = $statusManager;
        $this->helperData = $helperData;
    }

    /**
     * @inheritDoc
     */
    public function processNotification()
    {
        $response = $this->responseFactory->create();
        $data = $this->request->getBodyParams();

        try {
            $allowNotifications = $this->helperData->getAllowNotifications();
            if (!$allowNotifications) {
                throw new \Exception('Webhook notifications are disabled in Magento configuration');
            }

            if (empty($data)) {
                throw new \Exception('Webhook data cannot be empty');
            }

            if (empty($data['reference_id'])) {
                throw new \Exception('Reference id cannot be empty');
            }

            $sellerCodeFromData = !empty($data['seller_code']) ? $data['seller_code'] : '';
            $sellerCodeFromMagento = $this->helperData->getSellerCode();
            if ($sellerCodeFromData !== $sellerCodeFromMagento) {
                throw new \Exception('Seller code does not match the configured value');
            }

            $this->processData($data);

            $response->setSuccess(true);
            $response->setMessage('Webhook notification processed successfully');
        } catch (\Exception $e) {
            $this->logger->error('Error processing webhook: ' . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }

        return $response;
    }

    /**
     * Process the webhook data
     *
     * @param array $data
     * @return void
     */
    protected function processData($data)
    {
        $this->logger->info('Webhook received: ' . json_encode($data));

        if (empty($data['reference_id']) || empty($data['tracking']['last_status']['code'])) {
            return;
        }

        $referenceId = $data['reference_id'];
        $code = str_replace('-', "_", $data['tracking']['last_status']['code']);
        $trackingNum = !empty($data['tracking_nro']) ? $data['tracking_nro'] : '';
        $comment = $trackingNum ? __('Novedad de seguimiento: %1', $trackingNum) : __('Novedad de seguimiento');
        if (!empty($data['tracking']['last_status']['state'])){
            $comment .= ' | ' . __('Estado: %1', $data['tracking']['last_status']['state']);
        }
        if (!empty($data['tracking']['last_status']['description'])){
            $comment .= ' | ' . __('Descripción: %1', $data['tracking']['last_status']['description']);
        }
        $this->statusManager->processOrder($referenceId, $code, $comment);
    }
}
