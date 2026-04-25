<?php

namespace App\Application\Nid\Contracts;

interface OcrEngine
{
    public function extractText(string $imagePath, string $languages): string;
}
