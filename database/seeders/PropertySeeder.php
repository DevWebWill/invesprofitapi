<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PropertySeeder extends Seeder
{
    private const VALENCIA_ZONES = [
        'Ruzafa',
        'Benimaclet',
        'Patraix',
        'Extramurs',
        'Campanar',
        'El Carmen',
        'Malilla',
        'Cabanyal',
        'Aiora',
        'La Petxina',
    ];

    private const VALENCIA_ASSET_TYPES = [
        ['label' => 'Piso', 'type' => 'piso'],
        ['label' => 'Atico', 'type' => 'atico'],
        ['label' => 'Duplex', 'type' => 'duplex'],
        ['label' => 'Casa independiente', 'type' => 'casa_independiente'],
        ['label' => 'Chalet pareado', 'type' => 'chalet_pareado'],
        ['label' => 'Chalet adosado', 'type' => 'chalet_adosado'],
        ['label' => 'Casa rustica', 'type' => 'casa_rustica'],
    ];

    private const VALENCIA_ZONE_CENTERS = [
        'Ruzafa' => ['lat' => 39.4628, 'lng' => -0.3734],
        'Benimaclet' => ['lat' => 39.4848, 'lng' => -0.3575],
        'Patraix' => ['lat' => 39.4554, 'lng' => -0.3944],
        'Extramurs' => ['lat' => 39.4706, 'lng' => -0.3897],
        'Campanar' => ['lat' => 39.4816, 'lng' => -0.4015],
        'El Carmen' => ['lat' => 39.4768, 'lng' => -0.3812],
        'Malilla' => ['lat' => 39.4516, 'lng' => -0.3692],
        'Cabanyal' => ['lat' => 39.4718, 'lng' => -0.3293],
        'Aiora' => ['lat' => 39.4634, 'lng' => -0.3436],
        'La Petxina' => ['lat' => 39.4715, 'lng' => -0.3922],
    ];

    private const EXTRA_CITY_CONFIGS = [
        [
            'slug' => 'barcelona',
            'city' => 'Barcelona',
            'bounds' => ['south' => 41.32, 'west' => 2.06, 'north' => 41.47, 'east' => 2.23],
            'propertyCount' => 18,
            'averageYield' => 5.6,
            'investmentScore' => 88,
            'priceBase' => 285000,
            'priceSpread' => 335000,
            'rentBase' => 1280,
            'rentSpread' => 1620,
            'zones' => [
                'Eixample' => ['lat' => 41.3917, 'lng' => 2.1649],
                'Gracia' => ['lat' => 41.4036, 'lng' => 2.1564],
                'Poblenou' => ['lat' => 41.4032, 'lng' => 2.2049],
                'Sants' => ['lat' => 41.3750, 'lng' => 2.1401],
                'Born' => ['lat' => 41.3852, 'lng' => 2.1814],
            ],
        ],
        [
            'slug' => 'sevilla',
            'city' => 'Sevilla',
            'bounds' => ['south' => 37.33, 'west' => -6.05, 'north' => 37.44, 'east' => -5.90],
            'propertyCount' => 16,
            'averageYield' => 6.3,
            'investmentScore' => 80,
            'priceBase' => 176000,
            'priceSpread' => 198000,
            'rentBase' => 890,
            'rentSpread' => 920,
            'zones' => [
                'Triana' => ['lat' => 37.3830, 'lng' => -6.0026],
                'Nervion' => ['lat' => 37.3838, 'lng' => -5.9706],
                'Macarena' => ['lat' => 37.4032, 'lng' => -5.9915],
                'Alameda' => ['lat' => 37.3997, 'lng' => -5.9951],
            ],
        ],
        [
            'slug' => 'zaragoza',
            'city' => 'Zaragoza',
            'bounds' => ['south' => 41.61, 'west' => -0.95, 'north' => 41.69, 'east' => -0.84],
            'propertyCount' => 14,
            'averageYield' => 6.1,
            'investmentScore' => 77,
            'priceBase' => 154000,
            'priceSpread' => 154000,
            'rentBase' => 760,
            'rentSpread' => 740,
            'zones' => [
                'Centro' => ['lat' => 41.6481, 'lng' => -0.8898],
                'Delicias' => ['lat' => 41.6447, 'lng' => -0.9071],
                'Actur' => ['lat' => 41.6655, 'lng' => -0.8819],
                'La Magdalena' => ['lat' => 41.6528, 'lng' => -0.8735],
            ],
        ],
        [
            'slug' => 'bilbao',
            'city' => 'Bilbao',
            'bounds' => ['south' => 43.23, 'west' => -2.98, 'north' => 43.29, 'east' => -2.89],
            'propertyCount' => 12,
            'averageYield' => 5.4,
            'investmentScore' => 83,
            'priceBase' => 238000,
            'priceSpread' => 230000,
            'rentBase' => 980,
            'rentSpread' => 980,
            'zones' => [
                'Abando' => ['lat' => 43.2636, 'lng' => -2.9340],
                'Deusto' => ['lat' => 43.2705, 'lng' => -2.9493],
                'Indautxu' => ['lat' => 43.2611, 'lng' => -2.9438],
                'Casco Viejo' => ['lat' => 43.2586, 'lng' => -2.9236],
            ],
        ],
        [
            'slug' => 'alicante',
            'city' => 'Alicante',
            'bounds' => ['south' => 38.31, 'west' => -0.54, 'north' => 38.39, 'east' => -0.43],
            'propertyCount' => 14,
            'averageYield' => 6.5,
            'investmentScore' => 79,
            'priceBase' => 168000,
            'priceSpread' => 192000,
            'rentBase' => 820,
            'rentSpread' => 860,
            'zones' => [
                'Centro' => ['lat' => 38.3458, 'lng' => -0.4832],
                'Carolinas' => ['lat' => 38.3556, 'lng' => -0.4782],
                'San Juan' => ['lat' => 38.3684, 'lng' => -0.4128],
                'Benalua' => ['lat' => 38.3389, 'lng' => -0.4895],
            ],
        ],
        [
            'slug' => 'a-coruna',
            'city' => 'A Coruna',
            'bounds' => ['south' => 43.34, 'west' => -8.44, 'north' => 43.38, 'east' => -8.39],
            'propertyCount' => 10,
            'averageYield' => 5.9,
            'investmentScore' => 74,
            'priceBase' => 162000,
            'priceSpread' => 148000,
            'rentBase' => 730,
            'rentSpread' => 720,
            'zones' => [
                'Ensanche' => ['lat' => 43.3653, 'lng' => -8.4083],
                'Orzan' => ['lat' => 43.3702, 'lng' => -8.4061],
                'Monte Alto' => ['lat' => 43.3758, 'lng' => -8.4145],
                'Cuatro Caminos' => ['lat' => 43.3561, 'lng' => -8.4044],
            ],
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('properties')->truncate();

        $rows = [
            ...$this->generateValenciaMarkers(100),
            ...$this->generateValenciaRentalMarkers(24, 100),
            ...$this->generateExtraCityMarkers(),
            ...$this->manualMadridMalagaRows(),
        ];

        DB::table('properties')->insert($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateValenciaMarkers(int $total): array
    {
        $rows = [];

        for ($index = 0; $index < $total; $index++) {
            $zone = self::VALENCIA_ZONES[$index % count(self::VALENCIA_ZONES)];
            $center = self::VALENCIA_ZONE_CENTERS[$zone];
            $asset = self::VALENCIA_ASSET_TYPES[$index % count(self::VALENCIA_ASSET_TYPES)];

            $angle = $this->seededRandom($index + 1) * M_PI * 2;
            $radiusMeters = 120 + $this->seededRandom(($index + 1) * 7.1) * 1450;
            $latOffset = ($radiusMeters / 111320) * cos($angle);
            $lngOffset = ($radiusMeters / (111320 * cos($center['lat'] * M_PI / 180))) * sin($angle);

            $lat = $this->clamp($center['lat'] + $latOffset, 39.42, 39.52);
            $lng = $this->clamp($center['lng'] + $lngOffset, -0.44, -0.31);

            $yieldGross = round(5.3 + $this->seededRandom(($index + 1) * 9.3) * 2.2, 1);
            $yieldNet = round($yieldGross - 1.1, 1);
            $investmentScore = 67 + (int) floor($this->seededRandom(($index + 1) * 5.4) * 27);
            $price = (int) round(158000 + $this->seededRandom(($index + 1) * 11.7) * 220000);
            $listingMode = $index % 5 === 0 ? 'rent' : 'sale';
            $isBothMode = $listingMode === 'sale' && $index % 11 === 0;
            $monthlyRent = ($listingMode === 'rent' || $isBothMode)
                ? (int) round(780 + $this->seededRandom(($index + 1) * 13.4) * 1050)
                : null;
            $bedrooms = 1 + (int) floor($this->seededRandom(($index + 1) * 3.9) * 4);
            $bathrooms = $bedrooms >= 3 ? 2 : 1;
            $areaM2 = 48 + (int) floor($this->seededRandom(($index + 1) * 4.7) * 95);

            $title = $asset['label'] . ' en ' . $zone;

            if ($listingMode === 'rent') {
                $title = $asset['label'] . ' en alquiler en ' . $zone;
            } elseif ($isBothMode) {
                $title = $asset['label'] . ' en ' . $zone . ' (venta + alquiler)';
            }

            $rows[] = $this->toDbRow([
                'external_id' => 'prop-val-' . ($index + 1),
                'title' => $title,
                'property_type' => $asset['type'],
                'listing_mode' => $listingMode,
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'price' => $price,
                'monthly_rent' => $monthlyRent,
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'area_m2' => $areaM2,
                'city' => 'Valencia',
                'region_slug' => 'valencia',
                'yield_gross' => $yieldGross,
                'yield_net' => $yieldNet,
                'investment_score' => $investmentScore,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateValenciaRentalMarkers(int $total, int $offset = 0): array
    {
        $rows = [];

        for ($index = 0; $index < $total; $index++) {
            $seedIndex = $offset + $index + 1;
            $zone = self::VALENCIA_ZONES[$seedIndex % count(self::VALENCIA_ZONES)];
            $center = self::VALENCIA_ZONE_CENTERS[$zone];
            $asset = self::VALENCIA_ASSET_TYPES[$seedIndex % count(self::VALENCIA_ASSET_TYPES)];

            $angle = $this->seededRandom($seedIndex * 1.7) * M_PI * 2;
            $radiusMeters = 180 + $this->seededRandom($seedIndex * 8.9) * 1900;
            $latOffset = ($radiusMeters / 111320) * cos($angle);
            $lngOffset = ($radiusMeters / (111320 * cos($center['lat'] * M_PI / 180))) * sin($angle);

            $lat = $this->clamp($center['lat'] + $latOffset, 39.42, 39.52);
            $lng = $this->clamp($center['lng'] + $lngOffset, -0.44, -0.31);

            $yieldGross = round(5.1 + $this->seededRandom($seedIndex * 10.6) * 2.9, 1);
            $yieldNet = round($yieldGross - 1.0, 1);
            $investmentScore = 69 + (int) floor($this->seededRandom($seedIndex * 6.8) * 24);
            $price = (int) round(149000 + $this->seededRandom($seedIndex * 12.1) * 210000);
            $monthlyRent = (int) round(760 + $this->seededRandom($seedIndex * 14.2) * 1120);
            $bedrooms = 1 + (int) floor($this->seededRandom($seedIndex * 4.3) * 4);
            $bathrooms = $bedrooms >= 3 ? 2 : 1;
            $areaM2 = 46 + (int) floor($this->seededRandom($seedIndex * 5.6) * 98);

            $rows[] = $this->toDbRow([
                'external_id' => 'prop-val-rent-' . $seedIndex,
                'title' => $asset['label'] . ' en alquiler en ' . $zone,
                'property_type' => $asset['type'],
                'listing_mode' => 'rent',
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'price' => $price,
                'monthly_rent' => $monthlyRent,
                'bedrooms' => $bedrooms,
                'bathrooms' => $bathrooms,
                'area_m2' => $areaM2,
                'city' => 'Valencia',
                'region_slug' => 'valencia',
                'yield_gross' => $yieldGross,
                'yield_net' => $yieldNet,
                'investment_score' => $investmentScore,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateExtraCityMarkers(): array
    {
        $rows = [];

        foreach (self::EXTRA_CITY_CONFIGS as $configIndex => $config) {
            $offset = 300 + ($configIndex * 100);
            $zoneNames = array_keys($config['zones']);

            for ($index = 0; $index < $config['propertyCount']; $index++) {
                $seedIndex = $offset + $index + 1;
                $zone = $zoneNames[$seedIndex % count($zoneNames)];
                $center = $config['zones'][$zone];
                $asset = self::VALENCIA_ASSET_TYPES[$seedIndex % count(self::VALENCIA_ASSET_TYPES)];
                $listingMode = $seedIndex % 3 === 0 ? 'rent' : 'sale';
                $isBothMode = $listingMode === 'sale' && $seedIndex % 8 === 0;

                $angle = $this->seededRandom($seedIndex * 2.1) * M_PI * 2;
                $radiusMeters = 140 + $this->seededRandom($seedIndex * 9.1) * 2100;
                $latOffset = ($radiusMeters / 111320) * cos($angle);
                $lngOffset = ($radiusMeters / (111320 * cos($center['lat'] * M_PI / 180))) * sin($angle);

                $lat = $this->clamp($center['lat'] + $latOffset, $config['bounds']['south'], $config['bounds']['north']);
                $lng = $this->clamp($center['lng'] + $lngOffset, $config['bounds']['west'], $config['bounds']['east']);

                $yieldGross = round($config['averageYield'] - 0.9 + $this->seededRandom($seedIndex * 10.5) * 2.5, 1);
                $yieldNet = round($yieldGross - 1.0, 1);
                $investmentScore = (int) ($config['investmentScore'] - 8 + floor($this->seededRandom($seedIndex * 6.2) * 16));
                $price = (int) round($config['priceBase'] + $this->seededRandom($seedIndex * 11.4) * $config['priceSpread']);
                $monthlyRent = ($listingMode === 'rent' || $isBothMode)
                    ? (int) round($config['rentBase'] + $this->seededRandom($seedIndex * 14.4) * $config['rentSpread'])
                    : null;
                $bedrooms = 1 + (int) floor($this->seededRandom($seedIndex * 4.2) * 4);
                $bathrooms = $bedrooms >= 3 ? 2 : 1;
                $areaM2 = 44 + (int) floor($this->seededRandom($seedIndex * 5.2) * 110);

                $title = $asset['label'] . ' en ' . $zone;

                if ($listingMode === 'rent') {
                    $title = $asset['label'] . ' en alquiler en ' . $zone;
                } elseif ($isBothMode) {
                    $title = $asset['label'] . ' en ' . $zone . ' (venta + alquiler)';
                }

                $rows[] = $this->toDbRow([
                    'external_id' => 'prop-' . $config['slug'] . '-' . $seedIndex,
                    'title' => $title,
                    'property_type' => $asset['type'],
                    'listing_mode' => $listingMode,
                    'lat' => round($lat, 6),
                    'lng' => round($lng, 6),
                    'price' => $price,
                    'monthly_rent' => $monthlyRent,
                    'bedrooms' => $bedrooms,
                    'bathrooms' => $bathrooms,
                    'area_m2' => $areaM2,
                    'city' => $config['city'],
                    'region_slug' => $config['slug'],
                    'yield_gross' => $yieldGross,
                    'yield_net' => $yieldNet,
                    'investment_score' => $investmentScore,
                ]);
            }
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function manualMadridMalagaRows(): array
    {
        return [
            $this->toDbRow([
                'external_id' => 'prop-mad-1',
                'title' => 'Chalet pareado en Tetuan (venta + alquiler)',
                'property_type' => 'chalet_pareado',
                'listing_mode' => 'sale',
                'lat' => 40.4601,
                'lng' => -3.6999,
                'price' => 372000,
                'monthly_rent' => 1620,
                'bedrooms' => 3,
                'bathrooms' => 2,
                'area_m2' => 102,
                'city' => 'Madrid',
                'region_slug' => 'madrid',
                'yield_gross' => 5.9,
                'yield_net' => 4.7,
                'investment_score' => 79,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mad-2',
                'title' => 'Atico en alquiler en Chamberi',
                'property_type' => 'atico',
                'listing_mode' => 'rent',
                'lat' => 40.4343,
                'lng' => -3.7035,
                'price' => 419000,
                'monthly_rent' => 1850,
                'bedrooms' => 2,
                'bathrooms' => 2,
                'area_m2' => 88,
                'city' => 'Madrid',
                'region_slug' => 'madrid',
                'yield_gross' => 5.2,
                'yield_net' => 4.1,
                'investment_score' => 71,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mad-3',
                'title' => 'Piso en Usera',
                'property_type' => 'piso',
                'listing_mode' => 'sale',
                'lat' => 40.3869,
                'lng' => -3.7064,
                'price' => 248000,
                'monthly_rent' => null,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'area_m2' => 76,
                'city' => 'Madrid',
                'region_slug' => 'madrid',
                'yield_gross' => 6.2,
                'yield_net' => 5.1,
                'investment_score' => 81,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mad-4',
                'title' => 'Casa independiente en alquiler en Salamanca',
                'property_type' => 'casa_independiente',
                'listing_mode' => 'rent',
                'lat' => 40.4295,
                'lng' => -3.6838,
                'price' => 495000,
                'monthly_rent' => 2490,
                'bedrooms' => 4,
                'bathrooms' => 2,
                'area_m2' => 126,
                'city' => 'Madrid',
                'region_slug' => 'madrid',
                'yield_gross' => 4.8,
                'yield_net' => 3.9,
                'investment_score' => 68,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mal-1',
                'title' => 'Casa rustica en Centro Historico',
                'property_type' => 'casa_rustica',
                'listing_mode' => 'sale',
                'lat' => 36.7218,
                'lng' => -4.4184,
                'price' => 289000,
                'monthly_rent' => null,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'area_m2' => 71,
                'city' => 'Malaga',
                'region_slug' => 'malaga',
                'yield_gross' => 7.1,
                'yield_net' => 5.9,
                'investment_score' => 86,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mal-2',
                'title' => 'Duplex en alquiler en La Trinidad',
                'property_type' => 'duplex',
                'listing_mode' => 'rent',
                'lat' => 36.7271,
                'lng' => -4.4338,
                'price' => 219000,
                'monthly_rent' => 1320,
                'bedrooms' => 2,
                'bathrooms' => 1,
                'area_m2' => 65,
                'city' => 'Malaga',
                'region_slug' => 'malaga',
                'yield_gross' => 6.8,
                'yield_net' => 5.4,
                'investment_score' => 82,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mal-3',
                'title' => 'Piso en El Limonar',
                'property_type' => 'piso',
                'listing_mode' => 'sale',
                'lat' => 36.7201,
                'lng' => -4.3920,
                'price' => 336000,
                'monthly_rent' => null,
                'bedrooms' => 3,
                'bathrooms' => 2,
                'area_m2' => 96,
                'city' => 'Malaga',
                'region_slug' => 'malaga',
                'yield_gross' => 5.8,
                'yield_net' => 4.8,
                'investment_score' => 75,
            ]),
            $this->toDbRow([
                'external_id' => 'prop-mal-4',
                'title' => 'Chalet adosado en alquiler en Teatinos',
                'property_type' => 'chalet_adosado',
                'listing_mode' => 'rent',
                'lat' => 36.7175,
                'lng' => -4.4788,
                'price' => 247000,
                'monthly_rent' => 1460,
                'bedrooms' => 3,
                'bathrooms' => 2,
                'area_m2' => 84,
                'city' => 'Malaga',
                'region_slug' => 'malaga',
                'yield_gross' => 6.6,
                'yield_net' => 5.3,
                'investment_score' => 81,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toDbRow(array $row): array
    {
        $now = now();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $row;
    }

    private function seededRandom(float $seed): float
    {
        $x = sin($seed * 12.9898) * 43758.5453;

        return $x - floor($x);
    }

    private function clamp(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }
}
