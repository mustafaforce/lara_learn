<?php

namespace App\Infrastructure\Nid\Ocr;

use App\Application\Nid\Contracts\OcrEngine;
use GdImage;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class TesseractOcrEngine implements OcrEngine
{
    public function __construct(
        private string $binary,
        private string $psm,
        private string $psmCandidates,
        private bool $preprocessEnabled,
        private int $maxVariants,
        private int $maxRunsPerImage,
        private float $processTimeoutSeconds,
        private float $processIdleTimeoutSeconds,
    ) {
    }

    public function extractText(string $imagePath, string $languages): string
    {
        if (! is_file($imagePath)) {
            throw new RuntimeException('Image file not found for OCR.');
        }

        $outputs = [];
        $generatedVariantPaths = [];
        $variantPaths = $this->buildVariantPaths($imagePath, $generatedVariantPaths);
        $runs = 0;

        try {
            foreach ($variantPaths as $variantPath) {
                foreach ($this->resolvePsmCandidates() as $psm) {
                    if ($runs >= $this->maxRunsPerImage) {
                        break 2;
                    }

                    $process = new Process([
                        $this->binary,
                        $variantPath,
                        'stdout',
                        '-l',
                        $languages,
                        '--psm',
                        $psm,
                    ]);

                    $process->setTimeout($this->processTimeoutSeconds);
                    $process->setIdleTimeout($this->processIdleTimeoutSeconds);

                    try {
                        $process->run();
                    } catch (\Throwable) {
                        $runs++;
                        continue;
                    }

                    $runs++;

                    if (! $process->isSuccessful()) {
                        continue;
                    }

                    $text = trim($process->getOutput());
                    if ($text !== '') {
                        $outputs[] = $text;
                    }
                }
            }
        } finally {
            foreach ($generatedVariantPaths as $generatedVariantPath) {
                @unlink($generatedVariantPath);
            }
        }

        if ($outputs === []) {
            return '';
        }

        return $this->mergeOutputs($outputs);
    }

    /**
     * @return array<int, string>
     */
    private function resolvePsmCandidates(): array
    {
        $candidates = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $this->psmCandidates)
        )));

        if ($candidates === []) {
            $candidates = [$this->psm];
        }

        if (! in_array($this->psm, $candidates, true)) {
            array_unshift($candidates, $this->psm);
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @param  array<int, string>  $generatedVariantPaths
     * @return array<int, string>
     */
    private function buildVariantPaths(string $imagePath, array &$generatedVariantPaths): array
    {
        $variants = [$imagePath];

        if (! $this->preprocessEnabled || ! extension_loaded('gd')) {
            return $variants;
        }

        $source = $this->loadImageResource($imagePath);
        if (! $source instanceof GdImage) {
            return $variants;
        }

        try {
            $normalized = $this->normalizeOrientation($source, $imagePath);

            $scaled = $this->scaleImage($normalized, 2.0, 3200);
            $contrast = $this->cloneImage($scaled);
            imagefilter($contrast, IMG_FILTER_GRAYSCALE);
            imagefilter($contrast, IMG_FILTER_CONTRAST, -20);
            imagefilter($contrast, IMG_FILTER_BRIGHTNESS, 8);
            $this->sharpen($contrast);

            $softGray = $this->cloneImage($scaled);
            imagefilter($softGray, IMG_FILTER_GRAYSCALE);
            imagefilter($softGray, IMG_FILTER_CONTRAST, -10);

            foreach ([$normalized, $contrast, $softGray] as $variantImage) {
                $variantPath = $this->writeVariantPng($variantImage);
                if ($variantPath !== null) {
                    $variants[] = $variantPath;
                    $generatedVariantPaths[] = $variantPath;
                }
            }

            imagedestroy($normalized);
            imagedestroy($scaled);
            imagedestroy($contrast);
            imagedestroy($softGray);
        } finally {
            imagedestroy($source);
        }

        $variants = array_values(array_unique($variants));

        return array_slice($variants, 0, max(1, $this->maxVariants));
    }

    private function loadImageResource(string $imagePath): ?GdImage
    {
        $type = @exif_imagetype($imagePath);

        return match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath) ?: null,
            IMAGETYPE_PNG => @imagecreatefrompng($imagePath) ?: null,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($imagePath) ?: null) : null,
            default => null,
        };
    }

    private function normalizeOrientation(GdImage $image, string $path): GdImage
    {
        $normalized = $this->cloneImage($image);
        $orientation = null;

        if (@exif_imagetype($path) === IMAGETYPE_JPEG) {
            $exif = @exif_read_data($path);
            $orientation = $exif['Orientation'] ?? null;
        }

        return match ($orientation) {
            3 => $this->rotateImage($normalized, 180),
            6 => $this->rotateImage($normalized, -90),
            8 => $this->rotateImage($normalized, 90),
            default => $normalized,
        };
    }

    private function rotateImage(GdImage $image, int $angle): GdImage
    {
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private function scaleImage(GdImage $image, float $scale, int $maxDimension): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $targetScale = max(1.0, $scale);
        $largest = max($width, $height);

        if (($largest * $targetScale) > $maxDimension) {
            $targetScale = $maxDimension / $largest;
        }

        $targetWidth = max(1, (int) round($width * $targetScale));
        $targetHeight = max(1, (int) round($height * $targetScale));

        if ($targetWidth === $width && $targetHeight === $height) {
            return $this->cloneImage($image);
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $canvas instanceof GdImage) {
            return $this->cloneImage($image);
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function sharpen(GdImage $image): void
    {
        imageconvolution(
            $image,
            [
                [-1, -1, -1],
                [-1, 16, -1],
                [-1, -1, -1],
            ],
            8,
            0
        );
    }

    private function cloneImage(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $copy = imagecreatetruecolor($width, $height);

        if (! $copy instanceof GdImage) {
            throw new RuntimeException('Failed to allocate image buffer for OCR preprocessing.');
        }

        $white = imagecolorallocate($copy, 255, 255, 255);
        imagefill($copy, 0, 0, $white);
        imagecopy($copy, $image, 0, 0, 0, 0, $width, $height);

        return $copy;
    }

    private function writeVariantPng(GdImage $image): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'nid_ocr_');
        if ($tmpPath === false) {
            return null;
        }

        @unlink($tmpPath);
        $pngPath = $tmpPath.'.png';

        if (! imagepng($image, $pngPath, 6)) {
            @unlink($pngPath);

            return null;
        }

        return $pngPath;
    }

    /**
     * @param  array<int, string>  $outputs
     */
    private function mergeOutputs(array $outputs): string
    {
        $seen = [];
        $lines = [];

        foreach ($outputs as $output) {
            foreach (preg_split('/\R+/u', $output) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $line) ?? $line);
                if (isset($seen[$normalized])) {
                    continue;
                }

                $seen[$normalized] = true;
                $lines[] = $line;
            }
        }

        return trim(implode("\n", $lines));
    }
}
