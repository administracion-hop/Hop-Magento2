<?php

namespace Hop\Envios\Model\Health;

use Hop\Envios\Helper\Data as HelperHop;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Integration\Model\Integration;

/**
 * Revisiones que consultan la base (6 y 7). Report las cachea unos minutos.
 */
class DbChecks
{
    /** La tarea de despacho corre cada minuto: más de una hora sin éxito es que está detenida. */
    const CRON_JOB = 'hop_envios_genarate_shipment';
    const CRON_MAX_AGE_SECONDS = 3600;

    /** Recursos que habilitan a una integración a mandar avisos de estado. */
    const NOTIFICATION_RESOURCES = ['Hop_Envios::webhook_notifications', 'Magento_Backend::all'];

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var HelperHop
     */
    private $helper;

    /**
     * @param ResourceConnection $resource
     * @param HelperHop $helper
     */
    public function __construct(ResourceConnection $resource, HelperHop $helper)
    {
        $this->resource = $resource;
        $this->helper = $helper;
    }

    /**
     * 6. Tarea programada. Se mira la de despacho (corre cada minuto) y no la de 6 horas:
     * Magento borra por defecto el historial de ejecuciones exitosas después de 60 minutos.
     *
     * @param int|null $now timestamp, para las pruebas
     * @return array|null [severidad, mensaje]
     */
    public function checkCron($now = null)
    {
        $now = $now ?? time();
        $connection = $this->resource->getConnection();
        $lastSuccess = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), [new \Zend_Db_Expr('MAX(finished_at)')])
                ->where('job_code = ?', self::CRON_JOB)
                ->where('status = ?', 'success')
        );
        if (!$lastSuccess) {
            return [
                Checks::SEVERITY_ERROR,
                (string)__('La tarea programada de despacho de Hop nunca terminó bien: los pedidos no se despachan solos.'),
            ];
        }
        $lastTimestamp = strtotime($lastSuccess . ' UTC');
        if ($now - $lastTimestamp > self::CRON_MAX_AGE_SECONDS) {
            return [
                Checks::SEVERITY_ERROR,
                (string)__('La tarea programada de despacho de Hop no corre desde %1 (UTC): los pedidos no se despachan solos.', $lastSuccess),
            ];
        }
        return null;
    }

    /**
     * 7. Avisos de estado: activados y sin ninguna integración activa con el permiso de Hop.
     * No depende del nombre de la integración ("HOP Integration" es solo una sugerencia).
     *
     * @param int[] $storeIds vistas de tienda con Hop habilitado
     * @return array|null [severidad, mensaje, vistas de tienda afectadas]
     */
    public function checkNotifications(array $storeIds)
    {
        $affected = [];
        foreach ($storeIds as $storeId) {
            if ($this->helper->getAllowNotifications($storeId)) {
                $affected[] = (int)$storeId;
            }
        }
        if (!$affected || $this->hasNotificationIntegration()) {
            return null;
        }
        return [
            Checks::SEVERITY_ERROR,
            (string)__('Los avisos de estado de Hop están activados, pero no hay ninguna integración activa con el permiso "Webhook Notifications": Magento rechaza los avisos y los pedidos no cambian de estado.'),
            $affected,
        ];
    }

    /**
     * @return bool
     */
    public function hasNotificationIntegration()
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['i' => $this->resource->getTableName('integration')], [new \Zend_Db_Expr('1')])
            ->join(
                ['r' => $this->resource->getTableName('authorization_role')],
                'r.user_id = i.integration_id AND r.user_type = ' . (int)UserContextInterface::USER_TYPE_INTEGRATION,
                []
            )
            ->join(['ru' => $this->resource->getTableName('authorization_rule')], 'ru.role_id = r.role_id', [])
            ->where('i.status = ?', Integration::STATUS_ACTIVE)
            ->where('ru.permission = ?', 'allow')
            ->where('ru.resource_id IN (?)', self::NOTIFICATION_RESOURCES)
            ->limit(1);
        return (bool)$connection->fetchOne($select);
    }
}
