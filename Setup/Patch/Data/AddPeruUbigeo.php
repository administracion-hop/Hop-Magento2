<?php
declare(strict_types=1);

namespace Hop\Envios\Setup\Patch\Data;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddPeruUbigeo implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly ResourceConnection $resourceConnection,
        private readonly Dir $moduleDir
    ) {
    }

    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->resourceConnection->getConnection();

        $regionTable    = $this->resourceConnection->getTableName('directory_country_region');
        $provinciaTable = $this->resourceConnection->getTableName('hop_peru_provincia');
        $distritoTable  = $this->resourceConnection->getTableName('hop_peru_distrito');

        // Map departamento name → region_id (Magento regions for PE)
        $regionMap = $connection->fetchPairs(
            $connection->select()
                ->from($regionTable, ['default_name', 'region_id'])
                ->where('country_id = ?', 'PE')
        );

        $csvPath = $this->moduleDir->getDir('Hop_Envios') . '/etc/data/peru_ubigeo.csv';
        $rows    = $this->readCsv($csvPath);

        // --- Insert provincias (unique per departamento) ---
        $provinciaIndex = []; // "name|region_id" → true, used to dedupe before insert
        $provinciaRows  = [];
        foreach ($rows as $row) {
            $regionId   = (int)($regionMap[$row['departamento']] ?? 0);
            $name       = $this->toTitleCase($row['provincia']);
            $key        = $name . '|' . $regionId;
            if (!isset($provinciaIndex[$key])) {
                $provinciaIndex[$key] = true;
                $provinciaRows[]      = ['name' => $name, 'region_id' => $regionId];
            }
        }

        if ($provinciaRows) {
            $connection->insertMultiple($provinciaTable, $provinciaRows);
        }

        // Fetch back the inserted IDs keyed by "name|region_id"
        $provinciaIdMap = [];
        foreach ($connection->fetchAll($connection->select()->from($provinciaTable)) as $p) {
            $provinciaIdMap[$p['name'] . '|' . $p['region_id']] = (int)$p['provincia_id'];
        }

        // --- Insert distritos ---
        $distritoRows = [];
        foreach ($rows as $row) {
            $regionId   = (int)($regionMap[$row['departamento']] ?? 0);
            $provName   = $this->toTitleCase($row['provincia']);
            $provinciaId = $provinciaIdMap[$provName . '|' . $regionId] ?? null;
            if ($provinciaId === null) {
                continue;
            }
            $distritoRows[] = [
                'provincia_id' => $provinciaId,
                'name'         => $this->toTitleCase($row['distrito']),
                'ubigeo'       => $row['ubigeo'],
            ];
        }

        if ($distritoRows) {
            // Insert in chunks to avoid hitting packet-size limits
            foreach (array_chunk($distritoRows, 500) as $chunk) {
                $connection->insertMultiple($distritoTable, $chunk);
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [\Magento\Directory\Setup\Patch\Data\AddDataForPeru::class];
    }

    public function getAliases(): array
    {
        return [];
    }

    // ---------------------------------------------------------------------------

    private function readCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $rows;
        }
        fgetcsv($handle); // skip header
        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < 4) {
                continue;
            }
            $rows[] = [
                'ubigeo'       => trim($line[0]),
                'departamento' => trim($line[1]),
                'provincia'    => trim($line[2]),
                'distrito'     => trim($line[3]),
            ];
        }
        fclose($handle);
        return $rows;
    }

    private function toTitleCase(string $value): string
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }
}
