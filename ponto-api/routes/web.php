<?php

use App\Http\Controllers\Web\AdjustmentController;
use App\Http\Controllers\Web\Admin\CompanyController;
use App\Http\Controllers\Web\AuditLogController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DeviceController;
use App\Http\Controllers\Web\EmployeeController;
use App\Http\Controllers\Web\FiscalFileController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\PrivacyController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\TeamController;
use App\Http\Controllers\Web\TimesheetController;
use App\Http\Controllers\Web\WorkScheduleController;
use Illuminate\Support\Facades\Route;

// Painel RH / gestor / admin SaaS (Inertia + Vue). Telas via GET, ações via POST.

Route::get('/privacidade', PrivacyController::class)->name('privacy');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // Esqueci minha senha (o link do e-mail serve para painel e app).
    Route::get('/esqueci-senha', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/esqueci-senha', [PasswordResetController::class, 'send'])->middleware('throttle:password')->name('password.email');
    Route::get('/redefinir-senha/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/redefinir-senha', [PasswordResetController::class, 'update'])->middleware('throttle:password')->name('password.update');
});

Route::middleware(['auth', 'tenant'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Admin do SaaS
    Route::middleware('role:super_admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/empresas', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/empresas', [CompanyController::class, 'store'])->name('companies.store');
        Route::post('/empresas/{company}', [CompanyController::class, 'update'])->name('companies.update');
    });

    // Empresa: dono, RH e gestor (gestor vê só as próprias equipes)
    Route::middleware('role:owner,rh,gestor')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/espelho', [TimesheetController::class, 'show'])->name('timesheet.show');
        Route::get('/espelho/pdf', [TimesheetController::class, 'pdf'])->name('timesheet.pdf');
        Route::get('/espelho/csv', [TimesheetController::class, 'csv'])->name('timesheet.csv');

        Route::get('/ajustes', [AdjustmentController::class, 'index'])->name('adjustments.index');
        Route::post('/ajustes/{adjustment}/aprovar', [AdjustmentController::class, 'approve'])->name('adjustments.approve');
        Route::post('/ajustes/{adjustment}/rejeitar', [AdjustmentController::class, 'reject'])->name('adjustments.reject');
    });

    // Cadastros: dono e RH
    Route::middleware('role:owner,rh')->group(function () {
        Route::get('/funcionarios', [EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/funcionarios/novo', [EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/funcionarios', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/funcionarios/{employee}', [EmployeeController::class, 'edit'])->name('employees.edit');
        Route::post('/funcionarios/{employee}', [EmployeeController::class, 'update'])->name('employees.update');

        Route::get('/jornadas', [WorkScheduleController::class, 'index'])->name('schedules.index');
        Route::post('/jornadas', [WorkScheduleController::class, 'store'])->name('schedules.store');
        Route::post('/jornadas/{schedule}', [WorkScheduleController::class, 'update'])->name('schedules.update');

        Route::get('/equipes', [TeamController::class, 'index'])->name('teams.index');
        Route::post('/equipes', [TeamController::class, 'store'])->name('teams.store');
        Route::post('/equipes/{team}', [TeamController::class, 'update'])->name('teams.update');

        Route::post('/ajustes', [AdjustmentController::class, 'store'])->name('adjustments.store');

        Route::get('/aparelhos', [DeviceController::class, 'index'])->name('devices.index');
        Route::post('/aparelhos/{device}/liberar', [DeviceController::class, 'approve'])->name('devices.approve');
        Route::post('/aparelhos/{device}/recusar', [DeviceController::class, 'reject'])->name('devices.reject');
        Route::post('/aparelhos/{device}/desconectar', [DeviceController::class, 'revoke'])->name('devices.revoke');

        Route::get('/auditoria', [AuditLogController::class, 'index'])->name('audit.index');

        Route::get('/arquivos-fiscais', [FiscalFileController::class, 'index'])->name('fiscal.index');
        // POST: a senha do certificado vai no corpo. Limite contra tentativa de adivinhar a senha.
        Route::post('/arquivos-fiscais/afd', [FiscalFileController::class, 'afd'])->middleware('throttle:10,1')->name('fiscal.afd');

        Route::get('/configuracoes', [SettingsController::class, 'index'])->name('settings.index');
        Route::post('/configuracoes', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/configuracoes/logo', [SettingsController::class, 'uploadLogo'])->name('settings.logo');
        Route::post('/configuracoes/logo/remover', [SettingsController::class, 'removeLogo'])->name('settings.logo.remove');
        Route::post('/configuracoes/locais', [SettingsController::class, 'storeLocation'])->name('settings.locations.store');
        Route::post('/configuracoes/locais/{location}', [SettingsController::class, 'updateLocation'])->name('settings.locations.update');
        Route::post('/configuracoes/locais/{location}/remover', [SettingsController::class, 'destroyLocation'])->name('settings.locations.destroy');
    });
});
