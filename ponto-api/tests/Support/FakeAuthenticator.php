<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Autenticador WebAuthn de mentira (faz o papel do celular nos testes).
 * Gera uma chave P-256, monta clientDataJSON/authenticatorData no formato real
 * e assina com ES256 — o servidor verifica tudo como faria com um celular.
 */
class FakeAuthenticator
{
    private $key;

    private string $x;

    private string $y;

    public string $credentialId;

    public int $signCount = 0;

    public function __construct(public string $rpId = 'localhost', public string $origin = 'http://localhost:5173')
    {
        $this->key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'private_key_bits' => 2048,
            'config' => __DIR__.'/openssl.cnf', // o PHP do Windows não traz openssl.cnf
        ]) ?: throw new RuntimeException('Não foi possível gerar a chave de teste: '.openssl_error_string());
        $ec = openssl_pkey_get_details($this->key)['ec'];
        $this->x = str_pad($ec['x'], 32, "\0", STR_PAD_LEFT);
        $this->y = str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $this->credentialId = random_bytes(32);
    }

    /** Resposta de navigator.credentials.create() para as opções devolvidas pela API. */
    public function create(array $options, int $flags = 0x45): array
    {
        $clientData = $this->clientData('webauthn.create', $options['publicKey']['challenge']);
        $cose = $this->cbor([1 => 2, 3 => -7, -1 => 1, -2 => ['bytes' => $this->x], -3 => ['bytes' => $this->y]]);
        $authData = hash('sha256', $this->rpId, true)
            .chr($flags) // UP | UV | AT (credencial anexada)
            .pack('N', $this->signCount)
            .str_repeat("\0", 16) // aaguid
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$cose;
        $attestation = $this->cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => ['bytes' => $authData]]);

        return ['clientDataJSON' => self::b64url($clientData), 'attestationObject' => self::b64url($attestation)];
    }

    /** Resposta de navigator.credentials.get() (assinatura da batida). flags 0x05 = UP | UV. */
    public function get(array $options, int $flags = 0x05, ?string $origin = null): array
    {
        $this->signCount++;
        $clientData = $this->clientData('webauthn.get', $options['publicKey']['challenge'], $origin);
        $authData = hash('sha256', $this->rpId, true).chr($flags).pack('N', $this->signCount);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64url($this->credentialId),
            'clientDataJSON' => self::b64url($clientData),
            'authenticatorData' => self::b64url($authData),
            'signature' => self::b64url($signature),
        ];
    }

    private function clientData(string $type, string $challenge, ?string $origin = null): string
    {
        return json_encode(['type' => $type, 'challenge' => $challenge, 'origin' => $origin ?? $this->origin, 'crossOrigin' => false]);
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    // ---- CBOR mínimo: inteiros, textos, bytes (['bytes' => ...]) e mapas ----
    private function cbor(mixed $v): string
    {
        if (is_int($v)) {
            return $v >= 0 ? $this->head(0, $v) : $this->head(1, -1 - $v);
        }
        if (is_string($v)) {
            return $this->head(3, strlen($v)).$v;
        }
        if (is_array($v) && array_keys($v) === ['bytes']) {
            return $this->head(2, strlen($v['bytes'])).$v['bytes'];
        }
        $out = $this->head(5, count($v));
        foreach ($v as $k => $item) {
            $out .= $this->cbor($k).$this->cbor($item);
        }

        return $out;
    }

    private function head(int $major, int $len): string
    {
        return match (true) {
            $len < 24 => chr(($major << 5) | $len),
            $len < 256 => chr(($major << 5) | 24).chr($len),
            default => chr(($major << 5) | 25).pack('n', $len),
        };
    }
}
