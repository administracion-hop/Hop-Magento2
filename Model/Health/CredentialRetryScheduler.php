<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\HopEnviosRepository;

/**
 * Reprograma los despachos que esperan credenciales cuando la cuenta de su vista de tienda ya
 * funciona (último inicio de sesión real aceptado, ver CredentialState).
 *
 * Corre al principio de cada corrida de la tarea de despacho (Cron/GenarateShipment), fuera de
 * cualquier transacción, y no dentro del inicio de sesión: ahí puede estar el checkout o el
 * guardado de un envío, y actualizar otros pedidos dejaría bloqueos en la base hasta el final de
 * esa transacción. En el caso común (ningún pedido esperando) es una sola consulta.
 *
 * No depende de que la cuenta "cambie" de estado: si alguien carga una contraseña mal y vuelve a
 * la correcta, la cuenta correcta ya figuraba como buena y sus pedidos igual se reprograman.
 */
class CredentialRetryScheduler
{
    /**
     * @var HopEnviosRepository
     */
    private $hopEnviosRepository;

    /**
     * @var AccountResolver
     */
    private $accountResolver;

    /**
     * @var CredentialState
     */
    private $credentialState;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param HopEnviosRepository $hopEnviosRepository
     * @param AccountResolver $accountResolver
     * @param CredentialState $credentialState
     * @param LoggerInterface $logger
     */
    public function __construct(
        HopEnviosRepository $hopEnviosRepository,
        AccountResolver $accountResolver,
        CredentialState $credentialState,
        LoggerInterface $logger
    ) {
        $this->hopEnviosRepository = $hopEnviosRepository;
        $this->accountResolver = $accountResolver;
        $this->credentialState = $credentialState;
        $this->logger = $logger;
    }

    /**
     * @return int cantidad de despachos reprogramados
     */
    public function run()
    {
        $waiting = $this->hopEnviosRepository->getStoresWaitingForCredentials();
        if (!$waiting) {
            return 0;
        }
        $ready = [];
        foreach ($waiting as $storeId) {
            $accountKey = $this->accountResolver->getAccountKey($storeId);
            if ($accountKey && $this->credentialState->getState($accountKey) === CredentialState::OK) {
                $ready[] = (int)$storeId;
            }
        }
        if (!$ready) {
            return 0;
        }
        $count = $this->hopEnviosRepository->scheduleCredentialRetries($ready);
        if ($count) {
            $this->logger->info(sprintf('Hop: %d despachos reprogramados al recuperarse las credenciales', $count));
        }
        return $count;
    }
}
