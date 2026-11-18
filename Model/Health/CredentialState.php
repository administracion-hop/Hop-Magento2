<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Magento\Framework\App\CacheInterface;

/**
 * Último estado conocido de las credenciales de cada cuenta de Hop.
 *
 * Lo actualizan las llamadas a Hop que ya existen (checkout, despacho, puntos) y la revisión
 * periódica, pero solo con una respuesta real de Hop al inicio de sesión: un token guardado no
 * prueba nada. Para no sumar costo al checkout se lee de la caché de Magento y solo se escribe
 * cuando el estado cambia (de bien a rechazado o al revés).
 *
 * Los despachos que esperan credenciales no se reprograman acá (esto corre dentro del inicio de
 * sesión, que puede pasar en el checkout o en la transacción de guardado de un envío): los
 * reprograma CredentialRetryScheduler desde la tarea de despacho, mirando este estado.
 */
class CredentialState
{
    const OK = 'ok';
    const REJECTED = 'rejected';

    const CACHE_PREFIX = 'hop_envios_credentials_';

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var ResultStorage
     */
    private $storage;

    /**
     * @var AccountResolver
     */
    private $accountResolver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CacheInterface $cache
     * @param ResultStorage $storage
     * @param AccountResolver $accountResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheInterface $cache,
        ResultStorage $storage,
        AccountResolver $accountResolver,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->storage = $storage;
        $this->accountResolver = $accountResolver;
        $this->logger = $logger;
    }

    /**
     * Registra el resultado de un inicio de sesión real contra Hop.
     *
     * @param string|null $accountKey
     * @param string $state self::OK o self::REJECTED
     * @param string|null $detail mensaje de Hop, si rechazó
     * @return void
     */
    public function record($accountKey, $state, $detail = null)
    {
        if (!$accountKey) {
            return;
        }
        try {
            $previous = $this->getState($accountKey);
            if ($previous === $state) {
                return;
            }

            $storeIds = $this->accountResolver->getStoreIdsForAccount($accountKey);
            $this->storage->save(
                $accountKey,
                Checks::CREDENTIALS,
                $state === self::OK ? Checks::STATUS_OK : Checks::SEVERITY_ERROR,
                $state === self::OK ? null : (string)$detail,
                $storeIds
            );
            $this->cache->save($state, $this->cacheId($accountKey));
            $this->logger->info(sprintf(
                'Hop: credenciales de las vistas de tienda %s pasaron de %s a %s',
                implode(',', $storeIds),
                $previous ?: 'sin estado',
                $state
            ));
        } catch (\Throwable $e) {
            // Registrar el estado nunca puede romper el checkout ni un despacho.
            $this->logger->error('Hop: no se pudo registrar el estado de las credenciales: ' . $e->getMessage());
        }
    }

    /**
     * @param string $accountKey
     * @return string|null self::OK, self::REJECTED o null si nunca se registró
     */
    public function getState($accountKey)
    {
        $cached = $this->cache->load($this->cacheId($accountKey));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $row = $this->storage->load($accountKey, Checks::CREDENTIALS);
        if (!$row) {
            return null;
        }
        $state = $row['status'] === Checks::STATUS_OK ? self::OK : self::REJECTED;
        $this->cache->save($state, $this->cacheId($accountKey));
        return $state;
    }

    /**
     * Olvida el estado de una cuenta que ya no está configurada (ver Runner::cleanUp).
     *
     * @param string $accountKey
     * @return void
     */
    public function forget($accountKey)
    {
        $this->cache->remove($this->cacheId($accountKey));
    }

    /**
     * @param string $accountKey
     * @return string
     */
    private function cacheId($accountKey)
    {
        return self::CACHE_PREFIX . md5($accountKey);
    }
}
