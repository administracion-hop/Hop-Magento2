<?php

namespace Hop\Envios\Cron;

use Hop\Envios\Model\Health\Runner;
use Psr\Log\LoggerInterface;

/**
 * Revisión periódica del verificador (cada 6 horas): credenciales, vendedor, conexión,
 * cotización de prueba y medidas, para cada cuenta de Hop configurada.
 */
class HealthCheck
{
    /**
     * @var Runner
     */
    private $runner;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Runner $runner
     * @param LoggerInterface $logger
     */
    public function __construct(Runner $runner, LoggerInterface $logger)
    {
        $this->runner = $runner;
        $this->logger = $logger;
    }

    /**
     * @return void
     */
    public function execute()
    {
        try {
            $this->runner->run();
        } catch (\Throwable $e) {
            $this->logger->error('Hop: error en la revisión periódica de configuración: ' . $e->getMessage());
        }
    }
}
