<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\App\ResourceConnection;

/**
 * Dynamic option source for the "Distrito (Perú)" customer_address attribute.
 *
 * Options are the distinct distrito names from hop_peru_distrito. The stored
 * attribute value is the plain distrito name text. Hop\Envios\Helper\Data resolves the
 * ubigeo code by matching this value together with the separately-stored provincia
 * attribute and the address region, not by distrito name alone.
 */
class PeruDistritoSource extends AbstractSource
{
    public function __construct(private readonly ResourceConnection $resourceConnection)
    {
    }

    public function getAllOptions()
    {
        if ($this->_options === null) {
            $connection = $this->resourceConnection->getConnection();
            $table      = $this->resourceConnection->getTableName('hop_peru_distrito');

            $names = $connection->fetchCol(
                $connection->select()->from($table, ['name'])->distinct(true)->order('name ASC')
            );

            $this->_options = array_map(static fn (string $name) => ['label' => $name, 'value' => $name], $names);
        }

        return $this->_options;
    }
}
