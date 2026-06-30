<?php

namespace App\DataTransferObjects;

class PropertyDTO
{
    public function __construct(
        public readonly string $reference,
        public readonly string $parcelReference,
        public readonly string $province,
        public readonly string $municipality,
        public readonly string $postalCode,
        public readonly string $street,
        public readonly string $streetType,
        public readonly string $number,
        public readonly string $block,
        public readonly string $stair,
        public readonly string $floor,
        public readonly string $door,
        public readonly ?int $surface,
        public readonly ?int $year,
        public readonly string $usage,
    ) {
    }

    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'parcel_reference' => $this->parcelReference,
            'province' => $this->province,
            'municipality' => $this->municipality,
            'postal_code' => $this->postalCode,
            'street' => $this->street,
            'street_type' => $this->streetType,
            'number' => $this->number,
            'block' => $this->block,
            'stair' => $this->stair,
            'floor' => $this->floor,
            'door' => $this->door,
            'surface' => $this->surface,
            'year' => $this->year,
            'usage' => $this->usage,
        ];
    }
}
