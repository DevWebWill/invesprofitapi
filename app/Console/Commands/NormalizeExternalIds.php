<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Models\RawRentProperty;
use App\Models\RawSaleProperty;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class NormalizeExternalIds extends Command
{
    protected $signature = 'properties:normalize-external-ids {--dry-run : Solo muestra cambios sin persistir}';

    protected $description = 'Normaliza external_id existentes al formato source-listing_mode-id en properties y tablas raw';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Ejecutando normalización en modo dry-run (sin guardar cambios)...'
            : 'Ejecutando normalización y guardando cambios...');

        $propertiesStats = $this->normalizeProperties($dryRun);
        $saleStats = $this->normalizeRawTable(RawSaleProperty::query(), 'sale', $dryRun);
        $rentStats = $this->normalizeRawTable(RawRentProperty::query(), 'rent', $dryRun);
        $imagesStats = $this->normalizePropertyImages($dryRun);
        $legacyDirStats = $this->normalizeLegacyImageDirectories($dryRun);

        $this->newLine();
        $this->info('Resumen de normalización:');
        $this->line(sprintf(
            '  properties: total=%d, actualizados=%d, conflictos=%d, ya-normalizados=%d',
            $propertiesStats['total'],
            $propertiesStats['updated'],
            $propertiesStats['conflicts'],
            $propertiesStats['unchanged']
        ));
        $this->line(sprintf(
            '  raw_sale_properties: total=%d, actualizados=%d, conflictos=%d, ya-normalizados=%d',
            $saleStats['total'],
            $saleStats['updated'],
            $saleStats['conflicts'],
            $saleStats['unchanged']
        ));
        $this->line(sprintf(
            '  raw_rent_properties: total=%d, actualizados=%d, conflictos=%d, ya-normalizados=%d',
            $rentStats['total'],
            $rentStats['updated'],
            $rentStats['conflicts'],
            $rentStats['unchanged']
        ));
        $this->line(sprintf(
            '  property_images: propiedades=%d, rutas-actualizadas=%d, archivos-movidos=%d, conflictos=%d',
            $imagesStats['properties'],
            $imagesStats['paths_updated'],
            $imagesStats['files_moved'],
            $imagesStats['conflicts']
        ));
        $this->line(sprintf(
            '  legacy_image_dirs: escaneados=%d, renombrados=%d, conflictos=%d',
            $legacyDirStats['scanned'],
            $legacyDirStats['renamed'],
            $legacyDirStats['conflicts']
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{total:int, updated:int, conflicts:int, unchanged:int}
     */
    private function normalizeProperties(bool $dryRun): array
    {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'conflicts' => 0,
            'unchanged' => 0,
        ];

        Property::query()
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$stats, $dryRun): void {
                foreach ($chunk as $row) {
                    $stats['total']++;

                    $source = $this->normalizeSource($row->source ?? null);
                    $mode = $this->normalizeMode($row->listing_mode ?? null, 'sale');
                    $oldExternalId = (string) $row->external_id;
                    $newExternalId = $this->buildExternalId($source, $mode, $oldExternalId);

                    if ($newExternalId === $oldExternalId) {
                        $stats['unchanged']++;
                        continue;
                    }

                    $exists = Property::query()
                        ->where('external_id', $newExternalId)
                        ->whereKeyNot($row->id)
                        ->exists();

                    if ($exists) {
                        $stats['conflicts']++;
                        continue;
                    }

                    if (!$dryRun) {
                        $row->external_id = $newExternalId;
                        $row->source = $source;
                        $row->listing_mode = $mode;
                        $row->save();
                    }

                    $stats['updated']++;
                }
            });

        return $stats;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model> $query
     * @return array{total:int, updated:int, conflicts:int, unchanged:int}
     */
    private function normalizeRawTable($query, string $mode, bool $dryRun): array
    {
        $stats = [
            'total' => 0,
            'updated' => 0,
            'conflicts' => 0,
            'unchanged' => 0,
        ];

        $query
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$stats, $mode, $dryRun): void {
                foreach ($chunk as $row) {
                    $stats['total']++;

                    $source = $this->normalizeSource($row->source ?? null);
                    $oldExternalId = (string) $row->external_id;
                    $newExternalId = $this->buildExternalId($source, $mode, $oldExternalId);

                    if ($newExternalId === $oldExternalId) {
                        $stats['unchanged']++;
                        continue;
                    }

                    $modelClass = get_class($row);
                    $exists = $modelClass::query()
                        ->where('source', $source)
                        ->where('external_id', $newExternalId)
                        ->whereKeyNot($row->id)
                        ->exists();

                    if ($exists) {
                        $stats['conflicts']++;
                        continue;
                    }

                    if (!$dryRun) {
                        $row->source = $source;
                        $row->external_id = $newExternalId;
                        $row->save();
                    }

                    $stats['updated']++;
                }
            });

        return $stats;
    }

    private function normalizeSource(?string $source): string
    {
        $normalized = trim(strtolower((string) $source));
        return $normalized !== '' ? $normalized : 'fotocasa';
    }

    /**
     * @return array{properties:int, paths_updated:int, files_moved:int, conflicts:int}
     */
    private function normalizePropertyImages(bool $dryRun): array
    {
        $stats = [
            'properties' => 0,
            'paths_updated' => 0,
            'files_moved' => 0,
            'conflicts' => 0,
        ];

        $disk = Storage::disk('public');

        Property::query()
            ->whereNotNull('images')
            ->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use (&$stats, $disk, $dryRun): void {
                foreach ($chunk as $property) {
                    $images = is_array($property->images) ? $property->images : [];
                    if (!$images) {
                        continue;
                    }

                    $stats['properties']++;
                    $newImages = [];
                    $propertyChanged = false;

                    foreach ($images as $imagePath) {
                        $path = (string) $imagePath;
                        if ($path === '') {
                            continue;
                        }

                        $matches = [];
                        if (!preg_match('#^(raw-properties/[^/]+/[^/]+/)([^/]+)(/.+)$#', $path, $matches)) {
                            $newImages[] = $path;
                            continue;
                        }

                        $prefix = $matches[1];
                        $currentDirName = $matches[2];
                        $suffix = $matches[3];
                        $targetDirName = (string) $property->external_id;

                        if ($currentDirName === $targetDirName) {
                            $newImages[] = $path;
                            continue;
                        }

                        $targetPath = $prefix . $targetDirName . $suffix;
                        $finalTargetPath = $targetPath;

                        if (!$dryRun && $disk->exists($path)) {
                            $finalTargetPath = $this->resolveFileConflictPath($disk, $targetPath);
                            if ($finalTargetPath !== $targetPath) {
                                $stats['conflicts']++;
                            }

                            $targetDir = dirname($finalTargetPath);
                            if (!$disk->exists($targetDir)) {
                                $disk->makeDirectory($targetDir);
                            }

                            if ($disk->move($path, $finalTargetPath)) {
                                $stats['files_moved']++;
                            }
                        }

                        $newImages[] = $finalTargetPath;
                        $stats['paths_updated']++;
                        $propertyChanged = true;
                    }

                    if ($propertyChanged && !$dryRun) {
                        $property->images = $newImages;
                        $property->save();
                    }
                }
            });

        return $stats;
    }

    private function normalizeMode(?string $mode, string $fallback): string
    {
        $normalized = trim(strtolower((string) $mode));
        return in_array($normalized, ['sale', 'rent'], true) ? $normalized : $fallback;
    }

    private function buildExternalId(string $source, string $listingMode, string $rawExternalId): string
    {
        $rawPart = trim(strtolower($rawExternalId));
        if ($rawPart === '') {
            $rawPart = 'unknown';
        }

        $modePrefix = $source . '-' . $listingMode . '-';
        if (str_starts_with($rawPart, $modePrefix)) {
            return $rawPart;
        }

        $sourcePrefix = $source . '-';
        if (str_starts_with($rawPart, $sourcePrefix)) {
            $rawPart = substr($rawPart, strlen($sourcePrefix));
        }

        return $modePrefix . $rawPart;
    }

    private function resolveFileConflictPath($disk, string $targetPath): string
    {
        if (!$disk->exists($targetPath)) {
            return $targetPath;
        }

        $info = pathinfo($targetPath);
        $dir = $info['dirname'] ?? '';
        $filename = $info['filename'] ?? 'file';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        $counter = 1;
        do {
            $candidate = ($dir !== '' ? $dir . '/' : '') . $filename . '_migrated' . $counter . $extension;
            $counter++;
        } while ($disk->exists($candidate));

        return $candidate;
    }

    /**
     * @return array{scanned:int, renamed:int, conflicts:int}
     */
    private function normalizeLegacyImageDirectories(bool $dryRun): array
    {
        $stats = [
            'scanned' => 0,
            'renamed' => 0,
            'conflicts' => 0,
        ];

        $disk = Storage::disk('public');
        $baseDir = storage_path('app/public/raw-properties');

        if (!is_dir($baseDir)) {
            return $stats;
        }

        $sourceDirs = array_filter(glob($baseDir . '/*') ?: [], 'is_dir');
        foreach ($sourceDirs as $sourceDir) {
            $source = basename($sourceDir);
            $cityDirs = array_filter(glob($sourceDir . '/*') ?: [], 'is_dir');

            foreach ($cityDirs as $cityDir) {
                $entries = array_filter(glob($cityDir . '/*') ?: [], 'is_dir');
                foreach ($entries as $legacyDir) {
                    $legacyName = basename($legacyDir);
                    $sourcePrefix = $source . '-';

                    if (!str_starts_with($legacyName, $sourcePrefix)) {
                        continue;
                    }
                    if (str_starts_with($legacyName, $source . '-sale-') || str_starts_with($legacyName, $source . '-rent-')) {
                        continue;
                    }

                    $stats['scanned']++;

                    $suffix = trim(substr($legacyName, strlen($sourcePrefix)), '-');
                    if ($suffix === '') {
                        continue;
                    }

                    $mode = $this->guessListingModeForSuffix($source, $suffix);
                    $targetName = $source . '-' . $mode . '-' . $suffix;
                    $targetDir = $cityDir . DIRECTORY_SEPARATOR . $targetName;

                    if ($legacyDir === $targetDir) {
                        continue;
                    }

                    if ($dryRun) {
                        $stats['renamed']++;
                        continue;
                    }

                    $relativeLegacyDir = 'raw-properties/' . $source . '/' . basename($cityDir) . '/' . $legacyName;
                    $relativeTargetDir = 'raw-properties/' . $source . '/' . basename($cityDir) . '/' . $targetName;

                    $files = $disk->allFiles($relativeLegacyDir);
                    foreach ($files as $file) {
                        $filename = basename($file);
                        $targetPath = $relativeTargetDir . '/' . $filename;
                        $finalTargetPath = $this->resolveFileConflictPath($disk, $targetPath);
                        if ($finalTargetPath !== $targetPath) {
                            $stats['conflicts']++;
                        }

                        $disk->makeDirectory(dirname($finalTargetPath));
                        $disk->move($file, $finalTargetPath);
                    }

                    $disk->deleteDirectory($relativeLegacyDir);
                    $stats['renamed']++;
                }
            }
        }

        return $stats;
    }

    private function guessListingModeForSuffix(string $source, string $suffix): string
    {
        $rentId = $source . '-rent-' . $suffix;
        $saleId = $source . '-sale-' . $suffix;

        if (Property::query()->where('external_id', $rentId)->exists()) {
            return 'rent';
        }
        if (Property::query()->where('external_id', $saleId)->exists()) {
            return 'sale';
        }
        if (RawRentProperty::query()->where('source', $source)->where('external_id', $rentId)->exists()) {
            return 'rent';
        }
        if (RawSaleProperty::query()->where('source', $source)->where('external_id', $saleId)->exists()) {
            return 'sale';
        }

        return 'sale';
    }
}
