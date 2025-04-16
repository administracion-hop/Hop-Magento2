<?php

namespace Hop\Envios\Logger\Handler;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class ErrorHandler extends StreamHandler
{
    public function __construct()
    {
        $logFile = BP . '/var/log/error_hop_'.date('m_Y').'.log';
        parent::__construct($logFile, Logger::ERROR);
    }
}
