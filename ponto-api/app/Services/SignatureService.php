<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Assinatura destacada (.p7s) dos arquivos oficiais com o certificado A1 (.pfx) do desenvolvedor.
 *
 * A senha do certificado NÃO fica guardada no servidor: quem exporta digita na hora,
 * ela é usada só na memória durante a assinatura e descartada.
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
        return Storage::disk('local')->exists($this->path());
    }

    /**
     * Dados públicos do certificado (titular, emissor, validade), guardados na última
     * vez em que ele foi aberto com a senha. Não exigem senha para exibir.
     *
     * @return array{subject: string, issuer: string, valid_to: string, icp_brasil: bool}|null
     */
    public function certificateInfo(): ?array
    {
        if (! $this->configured()) {
            return null;
        }
        $json = Storage::disk('local')->get($this->infoPath());

        return $json ? json_decode($json, true) : null;
    }

    /**
     * Assina o conteúdo com a senha digitada e devolve o .p7s em DER.
     *
     * @return array{p7s: string, profile: string}
     *
     * @throws ValidationException senha incorreta (campo "password")
     */
    public function signDetached(string $content, #[\SensitiveParameter] string $password): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('Certificado digital não instalado. Em desenvolvimento, rode "php artisan ponto:certificado-teste".');
        }

        ['cert' => $cert, 'key' => $key, 'chain' => $chain] = $this->open($password);

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
                $cmd = [config('ponto.openssl_bin'), 'cms', '-sign', '-binary', '-cades', '-md', 'sha256', '-outform', 'DER',
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

    /**
     * Abre o .pfx com a senha e atualiza os dados públicos exibidos no painel.
     *
     * @return array{cert: string, key: string, chain: array<int, string>}
     */
    public function open(#[\SensitiveParameter] string $password): array
    {
        $pfx = Storage::disk('local')->get($this->path());

        if (! openssl_pkcs12_read($pfx, $out, $password)) {
            throw ValidationException::withMessages(['password' => 'Senha do certificado incorreta.']);
        }

        $this->rememberInfo($out['cert']);

        return ['cert' => $out['cert'], 'key' => $out['pkey'], 'chain' => $out['extracerts'] ?? []];
    }

    private function rememberInfo(string $certPem): void
    {
        $info = openssl_x509_parse($certPem);
        $issuer = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? '';

        Storage::disk('local')->put($this->infoPath(), json_encode([
            'subject' => $info['subject']['CN'] ?? '',
            'issuer' => $info['issuer']['CN'] ?? $issuer,
            'valid_to' => date('d/m/Y', $info['validTo_time_t']),
            'icp_brasil' => str_contains(strtoupper($issuer), 'ICP-BRASIL'),
        ]));
    }

    private function path(): string
    {
        return (string) config('ponto.certificate.path');
    }

    private function infoPath(): string
    {
        return $this->path().'.json';
    }

    private function opensslSupportsCades(): bool
    {
        static $supported = null;

        if ($supported === null) {
            $result = rescue(fn () => Process::run([config('ponto.openssl_bin'), 'cms', '-help']), null, false);
            $supported = $result !== null && str_contains($result->output().$result->errorOutput(), '-cades');
        }

        return $supported;
    }
}
