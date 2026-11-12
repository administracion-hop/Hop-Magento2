<?php

namespace Hop\Envios\Setup\Patch\Data;

use Hop\Envios\Model\CredentialRetryCriteria;
use Hop\Envios\Model\DispatchError;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Despachos que fallaron por credenciales antes de que existiera el motivo propio (tarea 12908).
 *
 * Hasta esta versión no se guardaba el código de respuesta de Hop, solo el texto. Hop responde
 * "Authorization fail" cuando no acepta el token (probado en el sandbox), así que esos fallos se
 * reconocen por el texto. Se marcan con el motivo de credenciales y se reprograman para ya: si
 * las credenciales ya funcionan se despachan; si no, quedan esperando a que vuelvan a funcionar.
 * Solo los que cumplen CredentialRetryCriteria: ni cancelados, cerrados, completos o retenidos,
 * ni de hace más de 30 días. Si el envío ya se creó a mano en Hop, Hop responde que la
 * referencia está en uso y no se duplica.
 */
class ClassifyCredentialFailures implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @return $this
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $hopEnvios = $this->moduleDataSetup->getTable('hop_envios');
        $select = $connection->select()
            ->from(['h' => $hopEnvios], ['entity_id'])
            ->join(['o' => $this->moduleDataSetup->getTable('sales_order')], 'o.entity_id = h.order_id', [])
            ->where('h.last_error_code IS NULL')
            ->where('h.last_error LIKE ?', '%Authorization fail%');
        CredentialRetryCriteria::apply($select);
        $ids = $connection->fetchCol($select);
        if ($ids) {
            $connection->update(
                $hopEnvios,
                [
                    'last_error_code' => DispatchError::CODE_CREDENTIALS,
                    'next_retry_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['entity_id IN (?)' => $ids]
            );
        }
        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases()
    {
        return [];
    }
}
