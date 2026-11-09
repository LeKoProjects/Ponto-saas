<?php

use App\Models\Company;
use App\Services\TimeEntryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('ponto:verificar-cadeia {--company= : ID da empresa}', function (TimeEntryService $service) {
    $companies = $this->option('company') ? Company::whereKey($this->option('company'))->get() : Company::all();
    $failures = 0;

    foreach ($companies as $company) {
        $broken = $service->verifyChain($company->id);
        if ($broken) {
            $failures++;
            $this->error("{$company->legal_name}: cadeia quebrada nos NSR ".implode(', ', array_slice($broken, 0, 20)));
            Log::critical('Cadeia de hash de ponto quebrada', ['company_id' => $company->id, 'nsr' => $broken]);
        } else {
            $this->info("{$company->legal_name}: cadeia íntegra.");
        }
    }

    return $failures ? 1 : 0;
})->purpose('Valida o hash encadeado das marcações de ponto');

// Job noturno de integridade (plano: "job noturno valida a cadeia").
Schedule::command('ponto:verificar-cadeia')->dailyAt('02:00')->timezone('America/Bahia');
Schedule::command('horizon:snapshot')->everyFiveMinutes();
