<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\AfdService;
use App\Services\AuditLogger;
use App\Services\SignatureService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/** Arquivos oficiais da Portaria 671/2021 para a fiscalização (AFD assinado). */
class FiscalFileController extends Controller
{
    public function __construct(private AfdService $afd, private SignatureService $signer, private AuditLogger $audit) {}

    public function index(): Response
    {
        return Inertia::render('FiscalFiles/Index', [
            'certificate' => rescue(fn () => $this->signer->certificateInfo(), null, false),
            'certificate_error' => $this->signer->configured() && ! rescue(fn () => $this->signer->certificateInfo(), null, false)
                ? 'O certificado configurado não abriu: confira a senha (PONTO_CERT_PASSWORD).'
                : null,
            'inpi' => (string) config('ponto.afd.inpi_registration'),
            'developer' => (string) config('ponto.afd.developer_document'),
            'defaults' => [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
            ],
        ]);
    }

    /** Baixa um .zip com o AFD (.txt) e a assinatura destacada (.p7s). */
    public function afd(Request $request): BinaryFileResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ], [
            'to.before_or_equal' => 'A data final não pode ser no futuro.',
        ]);

        $company = $request->user()->company;
        $from = Carbon::parse($data['from']);
        $to = Carbon::parse($data['to']);

        $afd = $this->afd->generate($company, $from, $to);
        $base = sprintf('AFD_%s_%s_%s', preg_replace('/\D/', '', $company->cnpj), $from->format('Ymd'), $to->format('Ymd'));

        $signature = $this->signer->configured() ? $this->signer->signDetached($afd['content']) : null;

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
