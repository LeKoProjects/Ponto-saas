<?php

use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\PunchController;
use Illuminate\Support\Facades\Route;

// API da PWA do funcionário (Sanctum token). Convenção do projeto: chamadas via POST.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/esqueci-senha', [AuthController::class, 'forgotPassword'])->middleware('throttle:password');

// "ability:ponto": só tokens emitidos pelo login do app valem aqui.
Route::middleware(['auth:sanctum', 'ability:ponto', 'tenant'])->group(function () {
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

        // Biometria do celular (WebAuthn).
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/biometria/opcoes', [BiometricController::class, 'registrationOptions']);
            Route::post('/biometria/cadastrar', [BiometricController::class, 'register']);
            Route::post('/biometria/desafio', [BiometricController::class, 'challenge']);
            Route::post('/biometria/desativar', [BiometricController::class, 'disable']);
        });
    });
});
