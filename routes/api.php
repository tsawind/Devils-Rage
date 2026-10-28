<?php

declare(strict_types=1);

use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MapSolarsystemController;
use App\Http\Controllers\Api\SovereigntyController;
use App\Http\Controllers\MapCameraController;
use Illuminate\Support\Facades\Route;

Route::get('sovereignties', [SovereigntyController::class, 'index'])->name('api.sovereignties.index');
// Patch 62: the video relay (MediaMTX) asks whether a publish / watch may go ahead.
Route::post('cameras/relay-auth', [MapCameraController::class, 'relayAuth'])->middleware('throttle:600,1')->name('api.cameras.relay-auth');

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    Route::resource('maps', MapController::class)->only(['update', 'show', 'index']);
    Route::resource('map-solarsystems', MapSolarsystemController::class)->only(['show', 'update', 'store', 'destroy']);
});
