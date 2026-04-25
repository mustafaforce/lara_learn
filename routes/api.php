<?php

use App\Http\Controllers\Api\V1\Nid\ExtractNidInformationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/nid/extract', ExtractNidInformationController::class);
});
