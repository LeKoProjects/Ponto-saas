<?php

namespace App\Services;

use App\Mail\TimeEntryReceiptMail;
use App\Models\Employee;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Registro de marcação (REP-P):
 * - hora SEMPRE do servidor (sincronizado via NTP/chrony com a.ntp.br);
 * - NSR sequencial por empregador e último hash lidos juntos com
 *   SELECT … FOR UPDATE em companies (uma consulta, sem varrer time_entries);
 * - hash encadeado por empresa: sha256(prev_hash | employee_id | nsr | marked_at);
 * - idempotência: o app manda um request_id (UUID) por toque; reenvio devolve a mesma batida.
 */
class TimeEntryService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public const SEQUENCE = ['entrada', 'intervalo_inicio', 'intervalo_fim', 'saida'];

    /** Atalho para quem tem o model (seeder, testes, jobs). */
    public function register(Employee $employee, array $meta = []): TimeEntry
    {
        return $this->registerFor([
            'employee_id' => $employee->id,
            'company_id' => $employee->company_id,
            'active' => (bool) $employee->active,
            // Não carrega a relação "user" parcial no model de quem chamou.
            'email' => $employee->relationLoaded('user') ? $employee->user?->email : $employee->user()->value('email'),
        ], $meta)['entry'];
    }

    /**
     * @param  array{employee_id: int, company_id: int, active: bool, email?: string|null}  $ctx  contexto em cache (ContextCache::employee)
     * @param  array{latitude?: float|null, longitude?: float|null, ip?: string|null, device?: string|null, request_id?: string|null}  $meta
     * @return array{entry: TimeEntry, replayed: bool}
     */
    public function registerFor(array $ctx, array $meta = []): array
    {
        if (! $ctx['active']) {
            throw new RuntimeException('Funcionário inativo.');
        }

        $requestId = $meta['request_id'] ?? null;
        if ($requestId && ($existing = $this->findByRequestId($ctx, $requestId))) {
            return ['entry' => $existing, 'replayed' => true];
        }

        try {
            $entry = DB::transaction(fn () => $this->insert($ctx, $meta, $requestId));
        } catch (UniqueConstraintViolationException $e) {
            // Dois envios simultâneos do mesmo toque: o outro venceu.
            if ($requestId && ($existing = $this->findByRequestId($ctx, $requestId))) {
                return ['entry' => $existing, 'replayed' => true];
            }
            throw $e;
        }

        if (! empty($ctx['email'])) {
            Mail::to($ctx['email'])->queue(new TimeEntryReceiptMail($entry));
        }

        return ['entry' => $entry, 'replayed' => false];
    }

    private function insert(array $ctx, array $meta, ?string $requestId): TimeEntry
    {
        $company = DB::table('companies')
            ->where('id', $ctx['company_id'])
            ->lockForUpdate()
            ->first(['next_nsr', 'last_hash', 'status']);

        if (! $company || $company->status !== 'active') {
            throw new RuntimeException('Empresa bloqueada.');
        }

        $nsr = (int) $company->next_nsr;
        $prevHash = $company->last_hash ?: self::GENESIS_HASH;
        $now = CarbonImmutable::now()->startOfSecond();

        $todayCount = TimeEntry::forCompany($ctx['company_id'])
            ->where('employee_id', $ctx['employee_id'])
            ->whereBetween('marked_at', [$now->startOfDay(), $now->endOfDay()])
            ->count();

        $hash = TimeEntry::computeHash($prevHash, $ctx['employee_id'], $nsr, $now->toIso8601String());

        $entry = TimeEntry::create([
            'company_id' => $ctx['company_id'],
            'employee_id' => $ctx['employee_id'],
            'nsr' => $nsr,
            'marked_at' => $now,
            'type' => self::SEQUENCE[$todayCount] ?? 'extra',
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
            'ip' => $meta['ip'] ?? null,
            'device' => isset($meta['device']) ? mb_substr($meta['device'], 0, 255) : null,
            'request_id' => $requestId,
            'prev_hash' => $prevHash,
            'hash' => $hash,
        ]);

        // Query builder de propósito: não dispara eventos do model (não invalida cache a cada batida).
        DB::table('companies')->where('id', $ctx['company_id'])->update(['next_nsr' => $nsr + 1, 'last_hash' => $hash]);

        return $entry;
    }

    private function findByRequestId(array $ctx, string $requestId): ?TimeEntry
    {
        return TimeEntry::forCompany($ctx['company_id'])
            ->where('employee_id', $ctx['employee_id'])
            ->where('request_id', $requestId)
            ->first();
    }

    /** Marcações de hoje do funcionário (para a tela e para o próximo tipo). */
    public function today(int $companyId, int $employeeId): Collection
    {
        return TimeEntry::forCompany($companyId)
            ->where('employee_id', $employeeId)
            ->whereBetween('marked_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('marked_at')
            ->get();
    }

    public static function nextTypeAfter(int $countToday): string
    {
        return self::SEQUENCE[$countToday] ?? 'extra';
    }

    /** Próximo tipo sugerido para exibir no botão da PWA. */
    public function nextType(Employee $employee): string
    {
        return self::nextTypeAfter($this->today($employee->company_id, $employee->id)->count());
    }

    /**
     * Dados do comprovante de registro do trabalhador.
     * Com $ctx (cache) não faz nenhuma consulta extra.
     */
    public function receipt(TimeEntry $entry, ?array $ctx = null): array
    {
        if (! $ctx) {
            $entry->loadMissing('employee.user', 'company');
            $ctx = [
                'legal_name' => $entry->company->legal_name,
                'cnpj' => $entry->company->cnpj,
                'name' => $entry->employee->user->name,
                'cpf' => $entry->employee->maskedCpf(),
            ];
        }

        return [
            'nsr' => $entry->nsr,
            'tipo' => TimeEntry::TYPES[$entry->type] ?? $entry->type,
            'data' => $entry->marked_at->format('d/m/Y'),
            'hora' => $entry->marked_at->format('H:i:s'),
            'fuso' => $entry->marked_at->format('P'),
            'empregador' => $ctx['legal_name'],
            'cnpj' => $ctx['cnpj'],
            'trabalhador' => $ctx['name'],
            'cpf' => $ctx['cpf'],
            'hash' => $entry->hash,
            'localizacao' => $entry->latitude !== null ? ['lat' => $entry->latitude, 'lng' => $entry->longitude] : null,
        ];
    }

    /**
     * Recalcula a cadeia de hashes da empresa. Retorna os NSR com divergência.
     *
     * @return array<int, int>
     */
    public function verifyChain(int $companyId): array
    {
        $broken = [];
        $prev = self::GENESIS_HASH;

        TimeEntry::forCompany($companyId)->orderBy('nsr')->chunk(500, function ($entries) use (&$prev, &$broken) {
            foreach ($entries as $e) {
                $expected = TimeEntry::computeHash($prev, $e->employee_id, $e->nsr, $e->marked_at->toIso8601String());
                if ($e->prev_hash !== $prev || $e->hash !== $expected) {
                    $broken[] = $e->nsr;
                }
                $prev = $e->hash;
            }
        });

        return $broken;
    }
}
