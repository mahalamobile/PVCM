<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function (): array {
    return [
        'service' => config('app.name'),
        'status' => 'ok',
    ];
});

Route::view('/docs', 'swagger');

Route::middleware('api.token')->get('/metrics', MetricsController::class);
