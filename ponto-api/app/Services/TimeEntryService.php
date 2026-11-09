<?php

namespace App\Services;

use App\Mail\TimeEntryReceiptMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Registro de marcação (REP-P):
 * - hora SEMPRE do servidor (sincronizado via NTP/chrony com a.ntp.br);
 * - NSR sequencial por empregador, obtido com SELECT … FOR UPDATE em companies;
 * - hash encadeado por empresa: sha256(prev_hash | employee_id | nsr | marked_at).
 */
class TimeEntryService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private const SEQUENCE = ['entrada', 'intervalo_inicio', 'intervalo_fim', 'saida'];

    /** @param array{latitude?: float|null, longitude?: float|null, ip?: string|null, device?: string|null} $meta */
    public function register(Employee $employee, array $meta = []): TimeEntry
    {
        if (! $employee->active) {
            throw new RuntimeException('Funcionário inativo.');
        }

        $entry = DB::transaction(function () use ($employee, $meta) {
            /** @var Company $company */
            $company = Company::query()->whereKey($employee->company_id)->lockForUpdate()->firstOrFail();

            if (! $company->isActive()) {
                throw new RuntimeException('Empresa bloqueada.');
            }

            $nsr = (int) $company->next_nsr;
            $company->next_nsr = $nsr + 1;
            $company->save();

            $prevHash = TimeEntry::forCompany($company->id)->orderByDesc('nsr')->value('hash') ?? self::GENESIS_HASH;

            $now = CarbonImmutable::now()->startOfSecond();

            $todayCount = TimeEntry::forCompany($company->id)
                ->where('employee_id', $employee->id)
                ->whereBetween('marked_at', [$now->startOfDay(), $now->endOfDay()])
                ->count();

            return TimeEntry::create([
                'company_id' => $company->id,
                'employee_id' => $employee->id,
                'nsr' => $nsr,
                'marked_at' => $now,
                'type' => self::SEQUENCE[$todayCount] ?? 'extra',
                'latitude' => $meta['latitude'] ?? null,
                'longitude' => $meta['longitude'] ?? null,
                'ip' => $meta['ip'] ?? null,
                'device' => isset($meta['device']) ? mb_substr($meta['device'], 0, 255) : null,
                'prev_hash' => $prevHash,
                'hash' => TimeEntry::computeHash($prevHash, $employee->id, $nsr, $now->toIso8601String()),
            ]);
        });

        $email = $employee->user?->email;
        if ($email) {
            Mail::to($email)->queue(new TimeEntryReceiptMail($entry));
        }

        return $entry;
    }

    /** Próximo tipo sugerido para exibir no botão da PWA. */
    public function nextType(Employee $employee): string
    {
        $count = TimeEntry::forCompany($employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('marked_at', [now()->startOfDay(), now()->endOfDay()])
            ->count();

        return self::SEQUENCE[$count] ?? 'extra';
    }

    /** Dados do comprovante de registro do trabalhador. */
    public function receipt(TimeEntry $entry): array
    {
        $entry->loadMissing('employee.user', 'company');

        return [
            'nsr' => $entry->nsr,
            'tipo' => TimeEntry::TYPES[$entry->type] ?? $entry->type,
            'data' => $entry->marked_at->format('d/m/Y'),
            'hora' => $entry->marked_at->format('H:i:s'),
            'fuso' => $entry->marked_at->format('P'),
            'empregador' => $entry->company->legal_name,
            'cnpj' => $entry->company->cnpj,
            'trabalhador' => $entry->employee->user->name,
            'cpf' => $entry->employee->maskedCpf(),
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
