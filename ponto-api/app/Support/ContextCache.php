<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Dados "parados" usados em toda batida (id, nome, CPF, empresa, CNPJ,
 * regra de GPS, jornada), guardados no Redis. Assim a requisição de ponto
 * não precisa recarregar funcionário, usuário e empresa a cada toque.
 *
 * Invalidação automática quando funcionário, usuário, empresa ou jornada
 * são salvos (ver AppServiceProvider).
 */
class ContextCache
{
    private const TTL_SECONDS = 43200; // 12 h — rede de segurança; a invalidação é por evento.

    /** @return array<string, mixed>|null */
    public static function employee(User $user): ?array
    {
        $ctx = Cache::get(self::employeeKey($user->id));
        if ($ctx !== null) {
            return $ctx;
        }

        $employee = Employee::withoutGlobalScope('company')
            ->with(['company', 'workSchedule' => fn ($q) => $q->withoutGlobalScope('company')])
            ->where('user_id', $user->id)
            ->first();

        if (! $employee) {
            return null;
        }

        $geofence = $employee->company->geofence_enabled
            ? $employee->company->workLocations()->orderBy('name')->get()->map->toContext()->all()
            : [];
        $geofence = $geofence ?: null;

        $ctx = [
            'employee_id' => $employee->id,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'cpf' => $employee->maskedCpf(),
            'active' => (bool) $employee->active,
            'company_id' => $employee->company_id,
            'company_status' => $employee->company->status,
            'cnpj' => $employee->company->cnpj,
            'legal_name' => $employee->company->legal_name,
            'company_name' => $employee->company->displayName(),
            'logo_path' => $employee->company->logo_path, // a URL é montada por requisição (localhost ou IP da rede)
            'privacy_email' => $employee->company->privacy_email,
            // Cerca virtual ligada também torna a localização obrigatória.
            'require_location' => (bool) $employee->company->require_location || $geofence !== null,
            'geofence' => $geofence,
            'schedule' => [
                'name' => $employee->workSchedule->name,
                'start' => substr($employee->workSchedule->start_time, 0, 5),
                'end' => substr($employee->workSchedule->end_time, 0, 5),
            ],
        ];

        Cache::put(self::employeeKey($user->id), $ctx, self::TTL_SECONDS);

        return $ctx;
    }

    /** @return array{id: int, status: string}|null */
    public static function company(?int $companyId): ?array
    {
        if (! $companyId) {
            return null;
        }

        return Cache::remember(self::companyKey($companyId), self::TTL_SECONDS, function () use ($companyId) {
            $company = Company::find($companyId);

            return $company ? ['id' => $company->id, 'status' => $company->status] : null;
        });
    }

    /** Status do aparelho do token (checado a cada requisição do app). */
    public static function device(int $deviceId): ?array
    {
        return Cache::remember("ponto:ctx:device:{$deviceId}", self::TTL_SECONDS, function () use ($deviceId) {
            $device = Device::withoutGlobalScope('company')->find($deviceId);

            return $device ? $device->only(['id', 'employee_id', 'status']) + ['biometric' => $device->hasBiometric(), 'api' => $device->toApiArray()] : null;
        });
    }

    public static function forgetDevice(int $deviceId): void
    {
        Cache::forget("ponto:ctx:device:{$deviceId}");
    }

    public static function forgetUser(int $userId): void
    {
        Cache::forget(self::employeeKey($userId));
    }

    public static function forgetCompany(int $companyId): void
    {
        Cache::forget(self::companyKey($companyId));
        User::where('company_id', $companyId)->pluck('id')->each(fn ($id) => self::forgetUser($id));
    }

    public static function forgetSchedule(int $scheduleId): void
    {
        Employee::withoutGlobalScope('company')
            ->where('work_schedule_id', $scheduleId)
            ->pluck('user_id')
            ->each(fn ($id) => self::forgetUser($id));
    }

    private static function employeeKey(int $userId): string
    {
        return "ponto:ctx:user:{$userId}";
    }

    private static function companyKey(int $companyId): string
    {
        return "ponto:ctx:company:{$companyId}";
    }
}
