<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Hop\Envios\Model\HopEnviosRepository;
use Magento\Framework\App\CacheInterface;

/**
 * Último estado conocido de las credenciales de cada cuenta de Hop.
 *
 * Lo actualizan las llamadas a Hop que ya existen (checkout, despacho, puntos) y la revisión
 * periódica, pero solo con una respuesta real de Hop al inicio de sesión: un token guardado no
 * prueba nada. Para no sumar costo al checkout se lee de la caché de Magento y solo se escribe
 * cuando el estado cambia (de bien a rechazado o al revés).
 *
 * Con cada inicio de sesión bueno se reprograman los despachos que fallaron por credenciales.
 * No alcanza con mirar el cambio de estado de la cuenta: si alguien carga una contraseña mal y
 * después vuelve a la correcta, la cuenta correcta ya figuraba como buena y no "cambia". Es solo
 * una consulta, y un inicio de sesión real pasa cuando vence el token o en la revisión.
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
     * @var HopEnviosRepository
     */
    private $hopEnviosRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param CacheInterface $cache
     * @param ResultStorage $storage
     * @param AccountResolver $accountResolver
     * @param HopEnviosRepository $hopEnviosRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        CacheInterface $cache,
        ResultStorage $storage,
        AccountResolver $accountResolver,
        HopEnviosRepository $hopEnviosRepository,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->storage = $storage;
        $this->accountResolver = $accountResolver;
        $this->hopEnviosRepository = $hopEnviosRepository;
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
                if ($state === self::OK) {
                    $this->scheduleRetries($this->accountResolver->getStoreIdsForAccount($accountKey));
                }
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

            if ($state === self::OK) {
                $this->scheduleRetries($storeIds);
            }
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
     * @param int[] $storeIds
     * @return void
     */
    private function scheduleRetries(array $storeIds)
    {
        if (!$storeIds) {
            return;
        }
        $count = $this->hopEnviosRepository->scheduleCredentialRetries($storeIds);
        if ($count) {
            $this->logger->info(sprintf('Hop: %d despachos reprogramados al recuperarse las credenciales', $count));
        }
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
