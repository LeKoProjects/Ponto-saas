<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AfdService;
use App\Services\AuditLogger;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Arquivos oficiais da Portaria 671/2021 para a fiscalização (AFD assinado). */
class FiscalFileController extends Controller
{
    public function __construct(private AfdService $afd, private SignatureService $signer, private AuditLogger $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('FiscalFiles/Index', [
            'certificate_installed' => $this->signer->configured(),
            'certificate' => $this->signer->certificateInfo(),
            'inpi' => (string) config('ponto.afd.inpi_registration'),
            'developer' => (string) config('ponto.afd.developer_document'),
            'defaults' => [
                'from' => $request->old('from', now()->startOfMonth()->toDateString()),
                'to' => $request->old('to', now()->toDateString()),
            ],
        ]);
    }

    /**
     * Baixa um .zip com o AFD (.txt) e a assinatura destacada (.p7s).
     * POST: a senha do certificado vai no corpo (nunca na URL) e não é guardada.
     */
    public function afd(Request $request): BinaryFileResponse
    {
        $installed = $this->signer->configured();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:today'],
            'password' => [$installed ? 'required' : 'nullable', 'string', 'max:200'],
        ], [
            'to.before_or_equal' => 'A data final não pode ser no futuro.',
            'password.required' => 'Digite a senha do certificado digital para assinar o arquivo.',
        ]);

        $company = $request->user()->company;
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        $afd = $this->afd->generate($company, $from, $to);
        $base = sprintf('AFD_%s_%s_%s', preg_replace('/\D/', '', $company->cnpj), $from->format('Ymd'), $to->format('Ymd'));

        try {
            $signature = $installed ? $this->signer->signDetached($afd['content'], $data['password']) : null;
        } catch (ValidationException $e) {
            // Tentativa com senha errada fica na auditoria (sem a senha).
            $this->audit->log('fiscal.afd_signature_failed', $company, null, ['reason' => 'senha incorreta']);
            throw $e;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'afd');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString("{$base}.txt", $afd['content']);
        if ($signature) {
            $zip->addFromString("{$base}.p7s", $signature['p7s']);
        }
        $zip->close();

        $this->audit->log('fiscal.afd_exported', $company, null, [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'records' => $afd['records'],
            'nsr' => $afd['first_nsr'] ? "{$afd['first_nsr']}-{$afd['last_nsr']}" : null,
            'signature' => $signature['profile'] ?? 'sem assinatura',
        ]);

        return response()->download($zipPath, "{$base}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
