<?php

namespace App\Application\Nid\DTOs;

use App\Domain\Nid\Entities\NidCardInfo;

final readonly class ExtractNidDataResult
{
    /**
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public NidCardInfo $cardInfo,
        public string $rawFrontText,
        public string $rawBackText,
        public array $warnings,
    ) {
    }

    public function toArray(): array
    {
        return [
            'data' => $this->cardInfo->toArray(),
            'raw_text' => [
                'front' => $this->rawFrontText,
                'back' => $this->rawBackText,
            ],
            'warnings' => $this->warnings,
        ];
    }
}
