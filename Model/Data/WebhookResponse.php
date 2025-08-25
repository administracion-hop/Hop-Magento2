<?php
namespace Hop\Envios\Model\Data;

use Hop\Envios\Api\Data\WebhookResponseInterface;
use Magento\Framework\DataObject;

/**
 * Webhook response model
 */
class WebhookResponse extends DataObject implements WebhookResponseInterface
{
    /**
     * @inheritDoc
     */
    public function getSuccess()
    {
        return $this->getData(self::SUCCESS);
    }

    /**
     * @inheritDoc
     */
    public function setSuccess($success)
    {
        return $this->setData(self::SUCCESS, $success);
    }

    /**
     * @inheritDoc
     */
    public function getMessage()
    {
        return $this->getData(self::MESSAGE);
    }

    /**
     * @inheritDoc
     */
    public function setMessage($message)
    {
        return $this->setData(self::MESSAGE, $message);
    }
}