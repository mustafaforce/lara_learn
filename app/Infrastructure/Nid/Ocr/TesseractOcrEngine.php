<?php

namespace App\Infrastructure\Nid\Ocr;

use App\Application\Nid\Contracts\OcrEngine;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class TesseractOcrEngine implements OcrEngine
{
    public function __construct(
        private string $binary,
        private string $psm,
    ) {
    }

    public function extractText(string $imagePath, string $languages): string
    {
        if (! is_file($imagePath)) {
            throw new RuntimeException('Image file not found for OCR.');
        }

        $process = new Process([
            $this->binary,
            $imagePath,
            'stdout',
            '-l',
            $languages,
            '--psm',
            $this->psm,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Tesseract OCR failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        return trim($process->getOutput());
    }
}
