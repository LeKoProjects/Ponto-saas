<?php

use App\Models\Company;
use App\Services\TimeEntryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

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

// Apaga do banco os tokens do app parados há mais tempo que o limite de inatividade (já não autenticam).
Artisan::command('ponto:limpar-tokens', function () {
    $days = config('ponto.token_idle_days');
    $deleted = PersonalAccessToken::query()
        ->whereRaw('COALESCE(last_used_at, created_at) < ?', [now()->subDays($days)])
        ->delete();
    $this->info("{$deleted} token(s) inativo(s) há mais de {$days} dias removido(s).");
})->purpose('Remove tokens do app expirados por inatividade');
Schedule::command('ponto:limpar-tokens')->dailyAt('03:00')->timezone('America/Bahia');

// Certificado AUTOASSINADO só para desenvolvimento/testes da assinatura do AFD.
// Em produção use o certificado A1 ICP-Brasil (e-CNPJ) do desenvolvedor do REP-P.
Artisan::command('ponto:certificado-teste {--force : Substitui o certificado existente}', function () {
    $path = (string) config('ponto.certificate.path');
    if (Storage::disk('local')->exists($path) && ! $this->option('force')) {
        $this->warn("Já existe um certificado em storage/app/private/{$path}. Use --force para substituir.");

        return 1;
    }

    // Gera pelo OpenSSL de linha de comando (a extensão do PHP no Windows costuma não achar o openssl.cnf).
    $password = (string) config('ponto.certificate.password');
    $dir = storage_path('app/private/tmp/cert-'.bin2hex(random_bytes(6)));
    mkdir($dir, 0700, true);
    try {
        $subject = '/C=BR/O=Ponto SaaS (TESTE - sem valor legal)/CN='.config('ponto.operator.name').' - TESTE';
        $req = Process::run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-sha256', '-days', '365', '-nodes',
            '-keyout', "$dir/key.pem", '-out', "$dir/cert.pem", '-subj', $subject]);
        $p12 = $req->successful() ? Process::run(['openssl', 'pkcs12', '-export', '-inkey', "$dir/key.pem", '-in', "$dir/cert.pem",
            '-out', "$dir/cert.pfx", '-passout', 'pass:'.$password]) : $req;
        if (! $p12->successful()) {
            $this->error('Falha ao gerar o certificado: '.$p12->errorOutput());

            return 1;
        }
        $pfx = file_get_contents("$dir/cert.pfx");
    } finally {
        array_map('unlink', glob("$dir/*"));
        @rmdir($dir);
    }
    Storage::disk('local')->put($path, $pfx);

    $this->info("Certificado de teste criado em storage/app/private/{$path} (senha: PONTO_CERT_PASSWORD do .env".($password === '' ? ', vazia' : '').').');
    $this->warn('Não é ICP-Brasil: serve só para testar. Para valor legal, use o e-CNPJ A1 do desenvolvedor.');
})->purpose('Gera um certificado autoassinado para testar a assinatura do AFD');
