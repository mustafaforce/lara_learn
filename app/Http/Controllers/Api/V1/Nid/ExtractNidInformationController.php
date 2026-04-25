<?php

namespace App\Http\Controllers\Api\V1\Nid;

use App\Application\Nid\DTOs\ExtractNidDataCommand;
use App\Application\Nid\UseCases\ExtractNidDataUseCase;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Nid\ExtractNidInformationRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ExtractNidInformationController extends Controller
{
    public function __invoke(ExtractNidInformationRequest $request, ExtractNidDataUseCase $useCase): JsonResponse
    {
        $disk = config('nid.upload.disk');
        $dir = trim((string) config('nid.upload.directory'), '/');

        $frontRelativePath = $request->file('front_image')->store($dir, $disk);
        $backRelativePath = $request->file('back_image')->store($dir, $disk);

        $frontAbsolutePath = Storage::disk($disk)->path($frontRelativePath);
        $backAbsolutePath = Storage::disk($disk)->path($backRelativePath);

        try {
            $result = $useCase->execute(new ExtractNidDataCommand(
                frontImagePath: $frontAbsolutePath,
                backImagePath: $backAbsolutePath,
                languages: $request->string('ocr_languages')->toString() ?: config('nid.ocr.tesseract.languages'),
            ));

            return response()->json([
                'message' => 'NID data extracted successfully.',
                ...$result->toArray(),
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'NID extraction failed.',
                'error' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unexpected server error during NID extraction.',
            ], 500);
        } finally {
            Storage::disk($disk)->delete([$frontRelativePath, $backRelativePath]);
        }
    }
}
