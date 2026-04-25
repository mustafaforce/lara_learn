<?php

namespace App\Infrastructure\Nid\Ocr;

use App\Application\Nid\Contracts\OcrEngine;
use RuntimeException;

final class NullOcrEngine implements OcrEngine
{
    public function extractText(string $imagePath, string $languages): string
    {
        throw new RuntimeException('OCR driver not configured. Set NID_OCR_DRIVER=tesseract and install Tesseract OCR.');
    }
}
