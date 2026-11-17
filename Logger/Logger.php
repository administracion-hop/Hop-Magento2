<?php

namespace Hop\Envios\Logger;

use Monolog\Logger as MonologLogger;
use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger implements LoggerInterface
{
    /**
     * @var MonologLogger
     */
    private $logger;

    public function __construct(MonologLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
