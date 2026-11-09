<?php

use App\Models\AuditLog;
use App\Services\AfdService;
use App\Services\TimeEntryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/* AFD (Portaria 671/2021, Anexo V) com assinatura destacada .p7s. */

beforeEach(function () {
    $this->c = makeCompany('Omicron');
    config(['ponto.afd.developer_document' => '11222333000181', 'ponto.afd.inpi_registration' => 'BR512026000001']);
    $service = app(TimeEntryService::class);
    Carbon::setTestNow('2026-11-03 08:00:10');
    $service->register($this->c['employee']);
    Carbon::setTestNow('2026-11-03 12:00:00');
    $service->register($this->c['employee']);
    Carbon::setTestNow('2026-11-04 08:02:00');
    $service->register($this->c['employee']);
    Carbon::setTestNow('2026-11-05 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

it('gera o AFD no leiaute oficial com tamanhos fixos e hash encadeado', function () {
    $afd = app(AfdService::class)->generate($this->c['company'], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-05'));
    $lines = explode("\r\n", rtrim($afd['content'], "\r\n"));
    $trailer = '999999999'.str_repeat('000000000', 5).'000000003'.'9';

    expect($afd['records'])->toBe(3)
        ->and(strlen($lines[0]))->toBe(302)
        ->and(substr($lines[0], 0, 10))->toBe('0000000001')
        ->and(substr($lines[0], 11, 14))->toBe($this->c['company']->cnpj)
        ->and(substr($lines[0], 206, 20))->toBe('2026-11-012026-11-05')
        ->and(substr($lines[0], 250, 3))->toBe('003')
        ->and(strlen($lines[1]))->toBe(137)
        ->and(substr($lines[1], 0, 10))->toBe('0000000017')
        ->and(substr($lines[1], 10, 24))->toBe('2026-11-03T08:00:10-0300')
        ->and(substr($lines[1], 34, 12))->toBe('0'.$this->c['employee']->cpf)
        ->and(strlen($lines[4]))->toBe(64)
        ->and($lines[4])->toBe($trailer)
        ->and(trim($lines[5]))->toBe('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S');

    // Hash = SHA-256(campos 1..7 + hash do registro tipo 7 anterior).
    $h1 = hash('sha256', substr($lines[1], 0, 73));
    $h2 = hash('sha256', substr($lines[2], 0, 73).$h1);
    expect(substr($lines[1], 73))->toBe($h1)->and(substr($lines[2], 73))->toBe($h2);
});

it('filtra o período sem quebrar a cadeia de hash', function () {
    $full = app(AfdService::class)->generate($this->c['company'], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-05'));
    $part = app(AfdService::class)->generate($this->c['company'], Carbon::parse('2026-11-04'), Carbon::parse('2026-11-04'));

    $fullLast = explode("\r\n", $full['content'])[3];
    $partLines = explode("\r\n", $part['content']);

    expect($part['records'])->toBe(1)->and($part['first_nsr'])->toBe(3)->and($partLines[1])->toBe($fullLast);
});

it('RH baixa o zip com AFD e assinatura .p7s e a exportação é auditada', function () {
    Storage::fake('local');
    $this->artisan('ponto:certificado-teste')->assertSuccessful();

    $this->actingAs($this->c['rh'])->get('/arquivos-fiscais')
        ->assertInertia(fn ($p) => $p->component('FiscalFiles/Index')->where('certificate.icp_brasil', false));

    $res = $this->actingAs($this->c['rh'])->get('/arquivos-fiscais/afd?from=2026-11-01&to=2026-11-05')->assertOk();
    $zipFile = $res->baseResponse->getFile()->getPathname();

    $zip = new ZipArchive;
    $zip->open($zipFile);
    $base = 'AFD_'.$this->c['company']->cnpj.'_20261101_20261105';
    $txt = $zip->getFromName("{$base}.txt");
    $p7s = $zip->getFromName("{$base}.p7s");
    $zip->close();

    expect($txt)->toContain('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S')
        ->and(ord($p7s[0]))->toBe(0x30) // DER: SEQUENCE
        ->and(AuditLog::where('action', 'fiscal.afd_exported')->first()->after)->toMatchArray(['records' => 3, 'nsr' => '1-3']);

    // Confere a assinatura com o OpenSSL (quando disponível na máquina).
    $dir = sys_get_temp_dir();
    file_put_contents("$dir/afd-test.txt", $txt);
    file_put_contents("$dir/afd-test.p7s", $p7s);
    $verify = rescue(fn () => Process::run(['openssl', 'cms', '-verify', '-inform', 'DER', '-binary', '-noverify',
        '-in', "$dir/afd-test.p7s", '-content', "$dir/afd-test.txt", '-out', "$dir/afd-test.out"]), null, false);
    if ($verify) {
        expect($verify->errorOutput())->toContain('Verification successful');
    }
});

it('gera o AFD sem assinatura quando não há certificado, e só dono/RH acessam', function () {
    Storage::fake('local');

    $this->actingAs($this->c['rh'])->get('/arquivos-fiscais/afd?from=2026-11-01&to=2026-11-05')->assertOk();
    $this->actingAs($this->c['rh'])->get('/arquivos-fiscais/afd?from=2026-11-05&to=2026-11-01')->assertSessionHasErrors('to');
    $this->actingAs($this->c['employee']->user)->get('/arquivos-fiscais')->assertForbidden();
});

it('não mistura marcações de outra empresa no AFD', function () {
    $other = makeCompany('Pi');
    app(TimeEntryService::class)->register($other['employee']);

    $afd = app(AfdService::class)->generate($this->c['company'], Carbon::parse('2026-11-01'), Carbon::parse('2026-11-05'));
    expect($afd['records'])->toBe(3)->and($afd['content'])->not->toContain($other['employee']->cpf);
});
