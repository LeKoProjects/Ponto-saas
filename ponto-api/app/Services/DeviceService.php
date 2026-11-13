<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Um aparelho por colaborador.
 * - Login em aparelho novo (ou recusado/desconectado) → solicitação "pendente" ao RH.
 * - RH libera → esse vira o único aparelho; os outros são desconectados (tokens apagados).
 * - Nada é excluído: a tabela devices + audit_logs formam o histórico.
 */
class DeviceService
{
    public function __construct(private AuditLogger $audit) {}

    /** Chamado no login do app: encontra o aparelho ou abre uma solicitação (auditada em nome do colaborador). */
    public function register(Employee $employee, string $uuid, string $name, ?string $userAgent, ?string $ip): Device
    {
        return $this->audit->as($employee->user_id, fn () => $this->findOrRequest($employee, $uuid, $name, $userAgent, $ip));
    }

    private function findOrRequest(Employee $employee, string $uuid, string $name, ?string $userAgent, ?string $ip): Device
    {
        $device = Device::forCompany($employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('uuid', $uuid)
            ->first();

        $name = mb_substr($name, 0, 160);

        if (! $device) {
            $device = Device::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'uuid' => $uuid,
                'name' => $name,
                'user_agent' => $userAgent,
                'status' => 'pendente',
                'requested_at' => now(),
                'last_seen_at' => now(),
                'last_ip' => $ip,
            ]);
            $this->audit->log('device.requested', $device, null, $device->only(['name', 'status']), $employee->company_id);

            return $device;
        }

        // Aparelho recusado ou desconectado pede liberação de novo.
        if (in_array($device->status, ['recusado', 'revogado'], true)) {
            $this->audit->update($device, [
                'status' => 'pendente',
                'requested_at' => now(),
                'name' => $name,
                'user_agent' => $userAgent,
                'last_seen_at' => now(),
                'last_ip' => $ip,
            ], 'device.requested');

            return $device;
        }

        $device->forceFill(['name' => $name, 'user_agent' => $userAgent, 'last_seen_at' => now(), 'last_ip' => $ip])->save();

        return $device;
    }

    /** Libera o aparelho e desconecta todos os outros do colaborador. */
    public function approve(Device $device, User $by): Device
    {
        if ($device->status === 'aprovado') {
            return $device;
        }

        DB::transaction(function () use ($device, $by) {
            $others = Device::forCompany($device->company_id)
                ->where('employee_id', $device->employee_id)
                ->whereKeyNot($device->id)
                ->whereIn('status', ['aprovado', 'pendente'])
                ->lockForUpdate()
                ->get();

            foreach ($others as $other) {
                if ($other->status === 'aprovado') {
                    $this->audit->update($other, [
                        'status' => 'revogado',
                        'revoked_at' => now(),
                        'revoked_by' => $by->id,
                        'revoke_reason' => "Substituído pelo aparelho {$device->name}",
                    ], 'device.revoked');
                } else {
                    $this->audit->update($other, [
                        'status' => 'recusado',
                        'decided_at' => now(),
                        'decided_by' => $by->id,
                        'revoke_reason' => "Outro aparelho foi liberado ({$device->name})",
                    ], 'device.rejected');
                }
                $this->disconnect($other);
            }

            $this->audit->update($device, [
                'status' => 'aprovado',
                'decided_at' => now(),
                'decided_by' => $by->id,
                'revoked_at' => null,
                'revoked_by' => null,
                'revoke_reason' => null,
            ], 'device.approved');
        });

        return $device;
    }

    public function reject(Device $device, User $by, ?string $reason = null): Device
    {
        if ($device->status !== 'pendente') {
            throw ValidationException::withMessages(['status' => 'Só é possível recusar um aparelho aguardando liberação.']);
        }

        $this->audit->update($device, [
            'status' => 'recusado',
            'decided_at' => now(),
            'decided_by' => $by->id,
            'revoke_reason' => $reason ?: 'Recusado pelo RH',
        ], 'device.rejected');
        $this->disconnect($device);

        return $device;
    }

    /** Desconecta um aparelho liberado (perdido, trocado, desligamento). */
    public function revoke(Device $device, User $by, ?string $reason = null): Device
    {
        if ($device->status !== 'aprovado') {
            throw ValidationException::withMessages(['status' => 'Só é possível desconectar um aparelho liberado.']);
        }

        $this->audit->update($device, [
            'status' => 'revogado',
            'revoked_at' => now(),
            'revoked_by' => $by->id,
            'revoke_reason' => $reason ?: 'Desconectado pelo RH',
        ], 'device.revoked');
        $this->disconnect($device);

        return $device;
    }

    /**
     * Apaga os tokens do aparelho (o app recebe 401 e volta para o login)
     * e a biometria cadastrada nele: se voltar a ser liberado, cadastra de novo.
     */
    private function disconnect(Device $device): void
    {
        PersonalAccessToken::where('device_id', $device->id)->delete();
        $device->forceFill([
            'webauthn_credential_id' => null,
            'webauthn_public_key' => null,
            'webauthn_sign_count' => 0,
            'webauthn_registered_at' => null,
        ])->save();
    }
}
