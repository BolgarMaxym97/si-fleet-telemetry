<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

// Prometheus scrape target (plain text, no auth, outside the api group).
Route::get('/metrics', MetricsController::class);
