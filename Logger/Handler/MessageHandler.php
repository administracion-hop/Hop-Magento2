<?php

namespace Hop\Envios\Logger\Handler;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class MessageHandler extends StreamHandler
{
    public function __construct()
    {
        $logFile = BP . '/var/log/hop'.date('m_Y').'.log';
        parent::__construct($logFile, Logger::INFO);
    }
}
