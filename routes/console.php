<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nid:ocr-check', function () {
    $binary = (string) config('nid.ocr.tesseract.binary', 'tesseract');
    $languagesConfig = (string) config('nid.ocr.tesseract.languages', 'ben+eng');
    $expectedLanguages = array_values(array_filter(explode('+', $languagesConfig)));

    $this->line('Checking Tesseract OCR runtime...');
    $this->line("Binary: {$binary}");
    $this->line("Expected languages: {$languagesConfig}");

    $versionProcess = new Process([$binary, '--version']);
    $versionProcess->run();

    if (! $versionProcess->isSuccessful()) {
        $this->error('Tesseract binary not available.');
        $this->line(trim($versionProcess->getErrorOutput() ?: $versionProcess->getOutput()));

        return 1;
    }

    $versionLine = trim(strtok($versionProcess->getOutput(), PHP_EOL) ?: '');
    $this->info("OK: {$versionLine}");

    $langsProcess = new Process([$binary, '--list-langs']);
    $langsProcess->run();

    if (! $langsProcess->isSuccessful()) {
        $this->error('Could not list Tesseract languages.');
        $this->line(trim($langsProcess->getErrorOutput() ?: $langsProcess->getOutput()));

        return 1;
    }

    $available = collect(preg_split('/\R/u', trim($langsProcess->getOutput())) ?: [])
        ->map(static fn (string $line): string => trim($line))
        ->filter(static fn (string $line): bool => $line !== '' && ! str_starts_with($line, 'List of available languages'))
        ->values();

    $missing = array_values(array_diff($expectedLanguages, $available->all()));

    if ($missing !== []) {
        $this->warn('Missing languages: '.implode(', ', $missing));
        $this->line('Available: '.$available->implode(', '));

        return 1;
    }

    $this->info('All expected OCR languages installed.');
    $this->line('Available: '.$available->implode(', '));

    return 0;
})->purpose('Check local Tesseract OCR binary and configured languages for NID extraction');
