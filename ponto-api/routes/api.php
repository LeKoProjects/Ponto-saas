<?php

use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PunchController;
use Illuminate\Support\Facades\Route;

// API da PWA do funcionário (Sanctum token). Convenção do projeto: chamadas via POST.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    // Liberadas mesmo com o aparelho aguardando o RH.
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/aparelho', [AuthController::class, 'device']);

    // Só no aparelho liberado pelo RH.
    Route::middleware('device')->group(function () {
        Route::post('/me', [AuthController::class, 'me']);
        Route::post('/ponto', [PunchController::class, 'store'])->middleware('throttle:ponto');
        Route::post('/historico', [PunchController::class, 'history']);
        Route::post('/comprovante/{entry}', [PunchController::class, 'receipt']);
        Route::post('/ajustes', [AdjustmentController::class, 'store'])->middleware('throttle:20,1');
        Route::post('/espelho/pdf', [AdjustmentController::class, 'pdf']);
    });
});
