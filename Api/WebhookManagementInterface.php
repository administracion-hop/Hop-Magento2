<?php
namespace Hop\Envios\Api;

/**
 * Webhook management interface
 * @api
 */
interface WebhookManagementInterface
{
    /**
     * Process webhook notification
     *
     * @return \Hop\Envios\Api\Data\WebhookResponseInterface
     */
    public function processNotification();
}
