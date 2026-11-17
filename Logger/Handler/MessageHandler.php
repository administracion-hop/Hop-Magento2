<?php

namespace Hop\Envios\Logger\Handler;

use Monolog\Handler\StreamHandler;
use Magento\Framework\App\Filesystem\DirectoryList;
use Monolog\Logger;

class MessageHandler extends StreamHandler
{
    public function __construct(DirectoryList $directoryList)
    {
        $logFile = $directoryList->getRoot() . '/var/log/hop'.date('m_Y').'.log';
        parent::__construct($logFile, Logger::INFO);
    }
}
