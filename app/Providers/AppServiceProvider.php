<?php

namespace App\Providers;

use App\Application\Nid\Contracts\OcrEngine;
use App\Infrastructure\Nid\Ocr\NullOcrEngine;
use App\Infrastructure\Nid\Ocr\TesseractOcrEngine;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OcrEngine::class, function (): OcrEngine {
            $driver = (string) config('nid.ocr.driver', 'tesseract');

            return match ($driver) {
                'tesseract' => new TesseractOcrEngine(
                    binary: (string) config('nid.ocr.tesseract.binary', 'tesseract'),
                    psm: (string) config('nid.ocr.tesseract.psm', '6'),
                ),
                default => new NullOcrEngine(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
