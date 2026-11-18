<?php

namespace Hop\Envios\Model\Health;

use Magento\Framework\App\ResourceConnection;

/**
 * Tabla hop_envios_health_check. Va en una tabla propia y no en core_config_data: escribir en la
 * configuración invalidaría su caché en cada revisión.
 *
 * Una fila por (scope_key, check_code). "since" es desde cuándo la revisión tiene el estado
 * actual: solo se mueve cuando el estado cambia.
 */
class ResultStorage
{
    const TABLE = 'hop_envios_health_check';

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Todas las filas, indexadas por "scope|código".
     *
     * @return array<string, array>
     */
    public function loadAll()
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll($connection->select()->from($this->resource->getTableName(self::TABLE)));
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['scope_key'] . '|' . $row['check_code']] = $row;
        }
        return $indexed;
    }

    /**
     * @param string $scopeKey
     * @param string $code
     * @return array|null
     */
    public function load($scopeKey, $code)
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('scope_key = ?', $scopeKey)
                ->where('check_code = ?', $code)
        );
        return $row ?: null;
    }

    /**
     * Guarda el resultado de una revisión. Si el estado no cambió, conserva "since".
     *
     * @param string $scopeKey
     * @param string $code
     * @param string $status Checks::STATUS_OK, SEVERITY_ERROR o SEVERITY_WARNING
     * @param string|null $message
     * @param int[] $storeIds
     * @param array|null $current fila actual, si ya se leyó
     * @return void
     */
    public function save($scopeKey, $code, $status, $message = null, array $storeIds = [], ?array $current = null)
    {
        $current = $current ?? $this->load($scopeKey, $code);
        $now = gmdate('Y-m-d H:i:s');
        $connection = $this->resource->getConnection();
        $connection->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'scope_key' => $scopeKey,
                'check_code' => $code,
                'status' => $status,
                'message' => $message,
                'store_ids' => implode(',', $storeIds),
                'since' => ($current && $current['status'] === $status) ? $current['since'] : $now,
                'checked_at' => $now,
            ],
            ['status', 'message', 'store_ids', 'since', 'checked_at']
        );
    }

    /**
     * @param string $scopeKey
     * @param string $code
     * @return void
     */
    public function delete($scopeKey, $code)
    {
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(self::TABLE),
            ['scope_key = ?' => $scopeKey, 'check_code = ?' => $code]
        );
    }

    /**
     * Borra los resultados de cuentas que ya no están configuradas en ninguna vista de tienda.
     *
     * @param string[] $currentAccountKeys
     * @return void
     */
    public function deleteStaleAccounts(array $currentAccountKeys)
    {
        $where = ['scope_key LIKE ?' => 'acct:%'];
        if ($currentAccountKeys) {
            $where['scope_key NOT IN (?)'] = $currentAccountKeys;
        }
        $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), $where);
    }
}
