<?php

namespace App\Application\Nid\DTOs;

final readonly class ExtractNidDataCommand
{
    public function __construct(
        public string $frontImagePath,
        public string $backImagePath,
        public string $languages,
    ) {
    }
}
