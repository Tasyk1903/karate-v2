<?php

use App\Http\Controllers\Offline\OfflineAuthController;
use App\Http\Controllers\Offline\OfflineTournamentController;
use App\Http\Middleware\MobileTokenAuth;
use App\Http\Middleware\OfflineOnly;
use Illuminate\Support\Facades\Route;

Route::prefix('offline')->group(function (): void {
    Route::post('login', [OfflineAuthController::class, 'login'])->middleware('throttle:5,1');
    Route::middleware([MobileTokenAuth::class, OfflineOnly::class])->group(function (): void {
        Route::get('me', [OfflineAuthController::class, 'me']);
        Route::post('logout', [OfflineAuthController::class, 'logout']);
        Route::get('tournaments', [OfflineTournamentController::class, 'index']);
        Route::get('lists/{list}', [OfflineTournamentController::class, 'show'])->whereNumber('list');
        Route::post('lists/{list}/sync', [OfflineTournamentController::class, 'sync'])->whereNumber('list')->middleware('throttle:120,1');
    });
});
