<?php

namespace App\Application\Nid\UseCases;

use App\Application\Nid\Contracts\OcrEngine;
use App\Application\Nid\DTOs\ExtractNidDataCommand;
use App\Application\Nid\DTOs\ExtractNidDataResult;
use App\Domain\Nid\Services\NidTextParser;

final readonly class ExtractNidDataUseCase
{
    public function __construct(
        private OcrEngine $ocrEngine,
        private NidTextParser $parser,
    ) {
    }

    public function execute(ExtractNidDataCommand $command): ExtractNidDataResult
    {
        $frontText = $this->ocrEngine->extractText($command->frontImagePath, $command->languages);
        $backText = $this->ocrEngine->extractText($command->backImagePath, $command->languages);

        $parsed = $this->parser->parse($frontText, $backText);

        return new ExtractNidDataResult(
            cardInfo: $parsed['info'],
            rawFrontText: $frontText,
            rawBackText: $backText,
            warnings: $parsed['warnings'],
        );
    }
}
