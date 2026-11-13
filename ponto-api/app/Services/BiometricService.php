<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;

/**
 * Biometria do celular via WebAuthn (a mesma tecnologia das passkeys).
 *
 * - O celular confirma a digital/rosto/bloqueio de tela e assina um desafio.
 * - O servidor guarda só a chave PÚBLICA, presa ao aparelho liberado pelo RH.
 * - Com biometria cadastrada no aparelho, a batida só é aceita com a assinatura válida.
 * - Desafios são de uso único e valem 5 minutos.
 */
class BiometricService
{
    private const CHALLENGE_TTL = 300;

    public function __construct(private AuditLogger $audit) {}

    /** Opções para navigator.credentials.create() (cadastro no celular). */
    public function registrationOptions(Device $device, User $user): object
    {
        $webauthn = $this->webauthn();
        $args = $webauthn->getCreateArgs(
            "ponto-device-{$device->id}",
            $user->email,
            $user->name,
            60,
            false,      // não precisa ser passkey sincronizada
            'required', // exige verificação do usuário (biometria ou bloqueio de tela)
            false,      // só o autenticador do próprio aparelho (não chave USB)
            $device->hasBiometric() ? [ByteBuffer::fromBase64Url($device->webauthn_credential_id)->getBinaryString()] : [],
        );
        Cache::put($this->key('create', $device), $webauthn->getChallenge()->getBinaryString(), self::CHALLENGE_TTL);

        return $args;
    }

    /** Confere a resposta do celular e guarda a credencial no aparelho. */
    public function register(Device $device, User $user, array $payload): Device
    {
        $challenge = Cache::pull($this->key('create', $device));
        if (! $challenge) {
            throw ValidationException::withMessages(['biometria' => 'O pedido de cadastro expirou. Tente ligar a biometria de novo.']);
        }

        try {
            $data = $this->webauthn()->processCreate(
                $this->bin($payload['clientDataJSON']),
                $this->bin($payload['attestationObject']),
                $challenge,
                true,  // verificação do usuário obrigatória
                true,  // presença obrigatória
                false, // sem conferir certificado do fabricante (atestado "none")
                false,
            );
        } catch (WebAuthnException $e) {
            throw ValidationException::withMessages(['biometria' => 'Não foi possível cadastrar a biometria: '.$e->getMessage()]);
        }

        $device->forceFill([
            'webauthn_credential_id' => $this->b64url($data->credentialId),
            'webauthn_public_key' => $data->credentialPublicKey,
            'webauthn_sign_count' => (int) $data->signatureCounter,
            'webauthn_registered_at' => now(),
        ])->save();

        $this->audit->as($user->id, fn () => $this->audit->log('device.biometric_registered', $device, null, ['name' => $device->name], $device->company_id));

        return $device;
    }

    /** Desafio para navigator.credentials.get() antes da batida. */
    public function assertionOptions(Device $device): object
    {
        if (! $device->hasBiometric()) {
            throw ValidationException::withMessages(['biometria' => 'Biometria não cadastrada neste aparelho.']);
        }

        $webauthn = $this->webauthn();
        $args = $webauthn->getGetArgs(
            [ByteBuffer::fromBase64Url($device->webauthn_credential_id)->getBinaryString()],
            60,
            false, false, false, false, // sem USB/NFC/BLE/celular de outra pessoa
            true,                        // só o autenticador do aparelho
            'required',
        );
        Cache::put($this->key('get', $device), $webauthn->getChallenge()->getBinaryString(), self::CHALLENGE_TTL);

        return $args;
    }

    /** Confere a assinatura enviada na batida. Lança erro de validação em "biometria". */
    public function verify(Device $device, ?array $assertion): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['biometria' => $msg]);

        if (! $assertion || empty($assertion['clientDataJSON']) || empty($assertion['authenticatorData']) || empty($assertion['signature'])) {
            $fail('Confirme com a biometria do celular para registrar o ponto.');
        }
        if (($assertion['id'] ?? null) !== $device->webauthn_credential_id) {
            $fail('A biometria usada não é a cadastrada neste aparelho.');
        }

        $challenge = Cache::pull($this->key('get', $device));
        if (! $challenge) {
            $fail('A confirmação por biometria expirou. Toque no botão de novo.');
        }

        $webauthn = $this->webauthn();
        try {
            $webauthn->processGet(
                $this->bin($assertion['clientDataJSON']),
                $this->bin($assertion['authenticatorData']),
                $this->bin($assertion['signature']),
                $device->webauthn_public_key,
                $challenge,
                $device->webauthn_sign_count ?: null,
                true, // verificação do usuário obrigatória
                true,
            );
        } catch (WebAuthnException $e) {
            $fail('Biometria não confirmada. Tente de novo.');
        }

        // Contador de assinaturas (detecta credencial clonada). Sem eventos: não precisa auditar cada batida.
        Device::withoutGlobalScopes()->whereKey($device->id)->update(['webauthn_sign_count' => (int) $webauthn->getSignatureCounter()]);
    }

    /** Desliga a biometria do aparelho (o colaborador desligou a chave ou ligou o reconhecimento facial). */
    public function disable(Device $device, User $user): void
    {
        if (! $device->hasBiometric()) {
            return;
        }
        $device->forceFill([
            'webauthn_credential_id' => null,
            'webauthn_public_key' => null,
            'webauthn_sign_count' => 0,
            'webauthn_registered_at' => null,
        ])->save();
        $this->audit->as($user->id, fn () => $this->audit->log('device.biometric_removed', $device, null, ['name' => $device->name], $device->company_id));
    }

    private function webauthn(): WebAuthn
    {
        // Atestado "none": não pedimos dados do fabricante do celular (privacidade).
        return new WebAuthn(config('app.name'), $this->rpId(), ['none'], true);
    }

    public function rpId(): string
    {
        return (string) (config('ponto.webauthn.rp_id') ?: parse_url((string) config('ponto.pwa_url'), PHP_URL_HOST) ?: 'localhost');
    }

    private function key(string $type, Device $device): string
    {
        return "ponto:webauthn:{$type}:{$device->id}";
    }

    private function bin(string $b64url): string
    {
        return ByteBuffer::fromBase64Url($b64url)->getBinaryString();
    }

    private function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
