<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Shared Provincia/Distrito option lists for the hop_peru_provincia/hop_peru_distrito tables,
 * used both by the checkout shipping-address form (UbigeoAddressAttributesLayoutProcessorPlugin)
 * and by the customer account "Address Book" form (ViewModel\Customer\UbigeoAddressAttributes).
 *
 * Peru provincia/distrito data is nationwide reference data, not store/website-scoped, so a
 * single global cache entry per option list is correct.
 */
class PeruLocationOptionsProvider
{
    /**
     * Tag shared with Hop\Envios\Model\Ubigeo\PeruUbigeoImporter, which cleans it after a
     * (re)import so this cache can't go stale.
     */
    public const CACHE_TAG = 'HOP_ENVIOS_PERU_UBIGEO';

    private const CACHE_KEY_PROVINCIA = 'hop_envios_peru_provincia_options';
    private const CACHE_KEY_DISTRITO  = 'hop_envios_peru_distrito_options';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CacheInterface $cache,
        private readonly JsonSerializer $serializer
    ) {
    }

    /**
     * Each option carries its departamento's Magento region_id under the "region_id" key,
     * so callers can filter Provincia options down to the chosen Region.
     *
     * @return array<int, array{value:string,label:string,region_id:string}>
     */
    public function getProvinciaOptions(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY_PROVINCIA);
        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('hop_peru_provincia');

        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['name', 'region_id'])->order('name ASC')
        );

        $options = array_map(
            static fn (array $row) => [
                'value'     => $row['name'],
                'label'     => $row['name'],
                'region_id' => (string)$row['region_id'],
            ],
            $rows
        );

        $this->cache->save($this->serializer->serialize($options), self::CACHE_KEY_PROVINCIA, [self::CACHE_TAG]);

        return $options;
    }

    /**
     * Each option carries the parent provincia name under the "provincia" key, so callers can
     * filter Distrito options down to the chosen Provincia.
     *
     * @return array<int, array{value:string,label:string,provincia:string}>
     */
    public function getDistritoOptions(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY_DISTRITO);
        if ($cached !== false) {
            return $this->serializer->unserialize($cached);
        }

        $connection     = $this->resourceConnection->getConnection();
        $distritoTable  = $this->resourceConnection->getTableName('hop_peru_distrito');
        $provinciaTable = $this->resourceConnection->getTableName('hop_peru_provincia');

        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['d' => $distritoTable], [])
                ->joinInner(['p' => $provinciaTable], 'p.provincia_id = d.provincia_id', [])
                ->columns(['distrito_name' => 'd.name', 'provincia_name' => 'p.name'])
                ->distinct(true)
                ->order('d.name ASC')
        );

        $options = array_map(
            static fn (array $row) => [
                'value'     => $row['distrito_name'],
                'label'     => $row['distrito_name'],
                'provincia' => $row['provincia_name'],
            ],
            $rows
        );

        $this->cache->save($this->serializer->serialize($options), self::CACHE_KEY_DISTRITO, [self::CACHE_TAG]);

        return $options;
    }
}
