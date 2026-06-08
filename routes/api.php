<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\PositionController;
use Illuminate\Support\Facades\Route;

Route::get('/vehicles', [PositionController::class, 'index']);
Route::get('/vehicles/{vehicleId}/positions/latest', [PositionController::class, 'latest']);
Route::get('/vehicles/{vehicleId}/track', [PositionController::class, 'track']);

Route::post('/assistant/ask', [AssistantController::class, 'ask']);
