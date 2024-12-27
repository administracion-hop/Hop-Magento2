<?php

namespace Hop\Envios\Model\ResourceModel;

/**
 * Class HopEnvios
 *
 * @version 1.0.0
 * @author Improntus <http://www.improntus.com> - Ecommerce done right
 * @copyright Copyright (c) 2021 Improntus
 * @package Hop\Envios\Model\ResourceModel
 */
class HopEnvios extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Construct
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Context $context
     * @param string|null $resourcePrefix
     */
    public function __construct(
        \Magento\Framework\Model\ResourceModel\Db\Context $context,
        $resourcePrefix = null
    ) {
        parent::__construct($context, $resourcePrefix);
    }

    public function _construct()
    {
        $this->_init('hop_envios','entity_id');
    }

     /**
     * Obtener datos de la base de datos por Order ID
     *
     * @param int $orderId
     * @return array
     */
    public function getDataByOrderId($orderId)
    {
        $connection = $this->getConnection();
        $tableName = $this->getTable('hop_envios');  // Nombre de la tabla

        // Crear consulta
        $select = $connection->select()
                             ->from($tableName)
                             ->where('order_id = ?', $orderId);

        // Ejecutar la consulta y devolver los resultados
        $result = $connection->fetchAll($select);
        return $result;
    }
}