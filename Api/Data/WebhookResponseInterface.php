<?php
namespace Hop\Envios\Api\Data;

/**
 * Webhook response interface
 * @api
 */
interface WebhookResponseInterface
{
    const SUCCESS = 'success';
    const MESSAGE = 'message';

    /**
     * Get success status
     *
     * @return bool
     */
    public function getSuccess();

    /**
     * Set success status
     *
     * @param bool $success
     * @return $this
     */
    public function setSuccess($success);

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage();

    /**
     * Set message
     *
     * @param string $message
     * @return $this
     */
    public function setMessage($message);
}