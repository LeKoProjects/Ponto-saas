<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Assinatura destacada (.p7s) dos arquivos oficiais com o certificado A1 (.pfx) do desenvolvedor.
 *
 * - Preferência: OpenSSL 3.2+ com "-cades" → CAdES-BES (inclui o atributo signingCertificateV2),
 *   o formato pedido pela Portaria 671/2021 para o AFD.
 * - Sem OpenSSL 3.2+: CMS/PKCS#7 destacado simples (válido como assinatura, mas sem os atributos CAdES).
 *
 * O certificado precisa ser ICP-Brasil para valor legal; o comando `ponto:certificado-teste`
 * gera um autoassinado apenas para desenvolvimento.
 */
class SignatureService
{
    public function configured(): bool
    {
        return Storage::disk('local')->exists((string) config('ponto.certificate.path'));
    }

    /** @return array{subject: string, issuer: string, valid_to: string, icp_brasil: bool}|null */
    public function certificateInfo(): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        ['cert' => $cert] = $this->loadPfx();
        $info = openssl_x509_parse($cert);
        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '';

        return [
            'subject' => $info['subject']['CN'] ?? '',
            'issuer' => $info['issuer']['CN'] ?? $issuer,
            'valid_to' => date('d/m/Y', $info['validTo_time_t']),
            'icp_brasil' => str_contains(strtoupper($issuer), 'ICP-BRASIL'),
        ];
    }

    /**
     * Assina o conteúdo e devolve o .p7s em DER.
     *
     * @return array{p7s: string, profile: string}
     */
    public function signDetached(string $content): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Certificado digital não configurado. Defina PONTO_CERT_PATH/PONTO_CERT_PASSWORD ou rode "php artisan ponto:certificado-teste" em desenvolvimento.');
        }

        ['cert' => $cert, 'key' => $key, 'chain' => $chain] = $this->loadPfx();

        $dir = storage_path('app/private/tmp/sign-'.bin2hex(random_bytes(8)));
        mkdir($dir, 0700, true);
        $files = [
            'in' => "$dir/in.bin", 'cert' => "$dir/cert.pem", 'key' => "$dir/key.pem",
            'chain' => "$dir/chain.pem", 'out' => "$dir/out.p7s",
        ];

        try {
            file_put_contents($files['in'], $content);
            file_put_contents($files['cert'], $cert);
            file_put_contents($files['key'], $key);
            file_put_contents($files['chain'], implode("\n", $chain));
            @chmod($files['key'], 0600);

            if ($this->opensslSupportsCades()) {
                $cmd = ['openssl', 'cms', '-sign', '-binary', '-cades', '-md', 'sha256', '-outform', 'DER',
                    '-in', $files['in'], '-signer', $files['cert'], '-inkey', $files['key'], '-out', $files['out']];
                if ($chain) {
                    array_push($cmd, '-certfile', $files['chain']);
                }
                $result = Process::run($cmd);
                if ($result->successful() && is_file($files['out'])) {
                    return ['p7s' => file_get_contents($files['out']), 'profile' => 'CAdES-BES'];
                }
            }

            // Plano B: CMS destacado pela extensão OpenSSL do PHP.
            $ok = openssl_cms_sign(
                $files['in'], $files['out'], 'file://'.$files['cert'], 'file://'.$files['key'], [],
                OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER,
                $chain ? $files['chain'] : null,
            );
            if (! $ok) {
                throw new RuntimeException('Falha ao assinar: '.openssl_error_string());
            }

            return ['p7s' => file_get_contents($files['out']), 'profile' => 'CMS'];
        } finally {
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** @return array{cert: string, key: string, chain: array<int, string>} */
    private function loadPfx(): array
    {
        $pfx = Storage::disk('local')->get((string) config('ponto.certificate.path'));

        if (! openssl_pkcs12_read($pfx, $out, (string) config('ponto.certificate.password'))) {
            throw new RuntimeException('Não foi possível abrir o certificado (.pfx): senha incorreta ou arquivo inválido.');
        }

        return ['cert' => $out['cert'], 'key' => $out['pkey'], 'chain' => $out['extracerts'] ?? []];
    }

    private function opensslSupportsCades(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $result = rescue(fn () => Process::run(['openssl', 'cms', '-help']), null, false);
            $supported = $result !== null && str_contains($result->output().$result->errorOutput(), '-cades');
        }

        return $supported;
    }
}
