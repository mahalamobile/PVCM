<?php

use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignDataController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.token')->group(function (): void {
    Route::post('/campaigns', [CampaignController::class, 'store'])
        ->middleware('throttle:client-campaign-create');

    Route::post('/campaigns/{campaign}/data', [CampaignDataController::class, 'store'])
        ->middleware('throttle:client-ingestion');

    Route::get('/health', HealthController::class);
});
