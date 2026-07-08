<?php
declare(strict_types=1);

namespace Hop\Envios\Model\Ubigeo;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Dir;

/**
 * Seeds the hop_peru_provincia / hop_peru_distrito mapping tables from the bundled CSV.
 *
 * Triggered on demand by an admin from the module configuration screen — not run
 * automatically on install/upgrade.
 */
class PeruUbigeoImporter
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Dir $moduleDir
    ) {
    }

    public function isImported(): bool
    {
        $connection = $this->resourceConnection->getConnection();

        $provinciaTable = $this->resourceConnection->getTableName('hop_peru_provincia');
        $distritoTable  = $this->resourceConnection->getTableName('hop_peru_distrito');

        return (bool)$connection->fetchOne($connection->select()->from($provinciaTable, ['COUNT(*)']))
            && (bool)$connection->fetchOne($connection->select()->from($distritoTable, ['COUNT(*)']));
    }

    /**
     * @return bool true if data was imported, false if it was already present (no-op)
     */
    public function import(): bool
    {
        if ($this->isImported()) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection();

        $regionTable    = $this->resourceConnection->getTableName('directory_country_region');
        $provinciaTable = $this->resourceConnection->getTableName('hop_peru_provincia');
        $distritoTable  = $this->resourceConnection->getTableName('hop_peru_distrito');

        $regionMap = $connection->fetchPairs(
            $connection->select()
                ->from($regionTable, ['default_name', 'region_id'])
                ->where('country_id = ?', 'PE')
        );

        $csvPath = $this->moduleDir->getDir('Hop_Envios') . '/etc/data/peru_ubigeo.csv';
        $rows    = $this->readCsv($csvPath);

        $unmatchedDepartamentos = array_unique(array_filter(
            array_map(
                fn(array $row) => isset($regionMap[$row['departamento']]) ? null : $row['departamento'],
                $rows
            )
        ));
        if ($unmatchedDepartamentos) {
            throw new \RuntimeException(
                'Peru ubigeo import aborted: no matching region for departamento(s): '
                . implode(', ', $unmatchedDepartamentos)
            );
        }

        $connection->beginTransaction();
        try {
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
                $regionId    = (int)($regionMap[$row['departamento']] ?? 0);
                $provName    = $this->toTitleCase($row['provincia']);
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

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return true;
    }

    private function readCsv(string $path): array
    {
        $rows   = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Peru ubigeo import aborted: unable to read CSV file at ' . $path);
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
