<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Logger\LoggerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Problemas actuales del verificador, para la franja y el detalle.
 *
 * Corre en cada pantalla del administrador, así que tiene que ser barato: lee configuración,
 * una consulta a hop_envios_health_check y dos consultas cacheadas (tarea programada y avisos de
 * estado). Nunca llama a Hop ni recorre el catálogo: eso lo hace Runner.
 *
 * Las revisiones que se calculan acá (LIVE_*) también se guardan en la tabla, solo cuando
 * cambia su estado, para saber desde cuándo existe cada problema.
 */
class Report
{
    const DB_CHECKS_CACHE_ID = 'hop_envios_health_db_checks';
    const DB_CHECKS_CACHE_LIFETIME = 300;

    /**
     * @var AccountResolver
     */
    private $accountResolver;

    /**
     * @var ConfigChecks
     */
    private $configChecks;

    /**
     * @var DbChecks
     */
    private $dbChecks;

    /**
     * @var ResultStorage
     */
    private $storage;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array|null
     */
    private $problems = null;

    /**
     * @param AccountResolver $accountResolver
     * @param ConfigChecks $configChecks
     * @param DbChecks $dbChecks
     * @param ResultStorage $storage
     * @param CacheInterface $cache
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     */
    public function __construct(
        AccountResolver $accountResolver,
        ConfigChecks $configChecks,
        DbChecks $dbChecks,
        ResultStorage $storage,
        CacheInterface $cache,
        SerializerInterface $serializer,
        LoggerInterface $logger
    ) {
        $this->accountResolver = $accountResolver;
        $this->configChecks = $configChecks;
        $this->dbChecks = $dbChecks;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->serializer = $serializer;
        $this->logger = $logger;
    }

    /**
     * Problemas agrupados: los que tienen la misma revisión y el mismo mensaje en varias vistas
     * de tienda van juntos. Errores primero.
     *
     * @return array<int, array{code: string, severity: string, title: string, message: string, fix: string, store_ids: int[], since: string|null}>
     */
    public function getProblems()
    {
        if ($this->problems !== null) {
            return $this->problems;
        }
        $this->problems = [];
        $activeStoreIds = $this->accountResolver->getActiveStoreIds();
        if (!$activeStoreIds) {
            return $this->problems;
        }

        $rows = $this->storage->loadAll();
        $found = array_merge(
            $this->collectLive($activeStoreIds, $rows),
            $this->collectStored($activeStoreIds, $rows)
        );

        $grouped = [];
        foreach ($found as $problem) {
            $key = $problem['code'] . '|' . $problem['severity'] . '|' . $problem['message'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = $problem;
                continue;
            }
            $grouped[$key]['store_ids'] = array_values(array_unique(array_merge($grouped[$key]['store_ids'], $problem['store_ids'])));
            if ($problem['since'] && (!$grouped[$key]['since'] || $problem['since'] < $grouped[$key]['since'])) {
                $grouped[$key]['since'] = $problem['since'];
            }
        }

        $order = array_flip(Checks::ORDER);
        usort($grouped, function ($a, $b) use ($order) {
            $severity = ($a['severity'] === Checks::SEVERITY_ERROR ? 0 : 1) - ($b['severity'] === Checks::SEVERITY_ERROR ? 0 : 1);
            return $severity ?: (($order[$a['code']] ?? 99) - ($order[$b['code']] ?? 99));
        });

        foreach ($grouped as &$problem) {
            sort($problem['store_ids']);
            $problem['title'] = Checks::getTitle($problem['code']);
            $problem['fix'] = Checks::getFix($problem['code']);
        }
        unset($problem);

        $this->problems = array_values($grouped);
        return $this->problems;
    }

    /**
     * @return array{errors: int, warnings: int}
     */
    public function getCounts()
    {
        $counts = ['errors' => 0, 'warnings' => 0];
        foreach ($this->getProblems() as $problem) {
            $counts[$problem['severity'] === Checks::SEVERITY_ERROR ? 'errors' : 'warnings']++;
        }
        return $counts;
    }

    /**
     * Revisiones calculadas al mostrar (2, 3, 4, 8, y 6 y 7 con caché). Sincroniza la tabla
     * para tener "desde cuándo": escribe solo si un problema aparece, cambia o se resuelve.
     *
     * @param int[] $activeStoreIds
     * @param array $rows
     * @return array
     */
    private function collectLive(array $activeStoreIds, array $rows)
    {
        $current = [];
        foreach ($activeStoreIds as $storeId) {
            foreach ($this->configChecks->run($storeId) as $code => $result) {
                $current[Checks::storeScope($storeId) . '|' . $code] = [$code, $result[0], $result[1], [$storeId], Checks::storeScope($storeId)];
            }
        }
        $db = $this->getDbChecks($activeStoreIds);
        if ($db['cron']) {
            $current[Checks::SCOPE_GLOBAL . '|' . Checks::CRON] = [Checks::CRON, $db['cron'][0], $db['cron'][1], $activeStoreIds, Checks::SCOPE_GLOBAL];
        }
        if ($db['notifications']) {
            $current[Checks::SCOPE_GLOBAL . '|' . Checks::NOTIFICATIONS] = [Checks::NOTIFICATIONS, $db['notifications'][0], $db['notifications'][1], $db['notifications'][2], Checks::SCOPE_GLOBAL];
        }

        $problems = [];
        $liveCodes = array_merge(Checks::LIVE_CONFIG, Checks::LIVE_DB);
        foreach ($current as $key => [$code, $severity, $message, $storeIds, $scope]) {
            $row = $rows[$key] ?? null;
            $since = $row['since'] ?? null;
            // Solo cuando cambia el estado: el mensaje se traduce al idioma de cada administrador,
            // así que compararlo reescribiría la fila en cada pantalla. Se muestra el mensaje
            // calculado ahora, no el guardado.
            if (!$row || $row['status'] !== $severity) {
                $this->safely(function () use ($scope, $code, $severity, $message, $storeIds, $row) {
                    $this->storage->save($scope, $code, $severity, $message, $storeIds, $row);
                });
                $since = ($row && $row['status'] === $severity) ? $row['since'] : gmdate('Y-m-d H:i:s');
            }
            $problems[] = $this->problem($code, $severity, $message, $storeIds, $since);
        }

        // Los que ya no fallan salen de la tabla.
        foreach ($rows as $key => $row) {
            if (in_array($row['check_code'], $liveCodes, true) && !isset($current[$key])) {
                $this->safely(function () use ($row) {
                    $this->storage->delete($row['scope_key'], $row['check_code']);
                });
            }
        }
        return $problems;
    }

    /**
     * Revisiones guardadas por Runner y por las llamadas normales a Hop (1, 5 y 9), solo de las
     * cuentas y vistas de tienda configuradas hoy.
     *
     * @param int[] $activeStoreIds
     * @param array $rows
     * @return array
     */
    private function collectStored(array $activeStoreIds, array $rows)
    {
        $scopes = [];
        foreach ($this->accountResolver->getAccounts() as $accountKey => $storeIds) {
            $scopes[$accountKey] = $storeIds;
        }
        foreach ($activeStoreIds as $storeId) {
            $scopes[Checks::storeScope($storeId)] = [$storeId];
        }

        $problems = [];
        foreach ($rows as $row) {
            if (!in_array($row['check_code'], Checks::REMOTE, true)
                || !isset($scopes[$row['scope_key']])
                || $row['status'] === Checks::STATUS_OK
            ) {
                continue;
            }
            $message = (string)$row['message'];
            if ($row['check_code'] === Checks::CREDENTIALS) {
                $message = (string)__('Hop rechazó el inicio de sesión%1.', $message !== '' ? ': ' . rtrim($message, '.') : '');
            }
            $problems[] = $this->problem($row['check_code'], $row['status'], $message, $scopes[$row['scope_key']], $row['since']);
        }
        return $problems;
    }

    /**
     * @param int[] $activeStoreIds
     * @return array{cron: array|null, notifications: array|null}
     */
    private function getDbChecks(array $activeStoreIds)
    {
        $cacheId = self::DB_CHECKS_CACHE_ID . '_' . md5(implode(',', $activeStoreIds));
        $cached = $this->cache->load($cacheId);
        if ($cached) {
            try {
                return $this->serializer->unserialize($cached);
            } catch (\Exception $e) {
                // Se recalcula.
            }
        }
        $result = [
            'cron' => $this->dbChecks->checkCron(),
            'notifications' => $this->dbChecks->checkNotifications($activeStoreIds),
        ];
        $this->cache->save($this->serializer->serialize($result), $cacheId, [], self::DB_CHECKS_CACHE_LIFETIME);
        return $result;
    }

    /**
     * @param string $code
     * @param string $severity
     * @param string $message
     * @param int[] $storeIds
     * @param string|null $since
     * @return array
     */
    private function problem($code, $severity, $message, array $storeIds, $since)
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'store_ids' => array_map('intval', $storeIds),
            'since' => $since ?: null,
        ];
    }

    /**
     * @param callable $write
     * @return void
     */
    private function safely(callable $write)
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->logger->error('Hop: no se pudo guardar el resultado de una revisión: ' . $e->getMessage());
        }
    }
}
