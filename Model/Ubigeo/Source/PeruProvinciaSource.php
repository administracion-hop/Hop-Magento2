<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\App\ResourceConnection;

/**
 * Dynamic option source for the "Provincia (Perú)" customer_address attribute.
 *
 * Options are the distinct provincia names from hop_peru_provincia, so the dropdown
 * always reflects whatever was last imported — no eav_attribute_option rows involved,
 * the stored attribute value is the plain provincia name text itself.
 */
class PeruProvinciaSource extends AbstractSource
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getAllOptions()
    {
        if ($this->_options === null) {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('hop_peru_provincia');

            $names = $connection->fetchCol(
                $connection->select()->from($table, ['name'])->distinct(true)->order('name ASC')
            );

            $this->_options = array_map(static fn (string $name) => ['label' => $name, 'value' => $name], $names);
        }

        return $this->_options;
    }
}
