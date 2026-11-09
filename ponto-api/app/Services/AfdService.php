<?php

namespace App\Services;

use App\Models\Company;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AFD — Arquivo Fonte de Dados do REP-P (Portaria MTP nº 671/2021, Anexo V, leiaute 003).
 *
 * Registros gerados:
 *   tipo 1 — cabeçalho (302 posições)
 *   tipo 7 — marcação de ponto do REP-P (137 posições)
 *   tipo 9 — trailer (64 posições)
 *   + linha final "ASSINATURA_DIGITAL_EM_ARQUIVO_P7S" (assinatura CAdES destacada no .p7s)
 *
 * Texto em ISO-8859-1, campos de tamanho fixo, linhas terminadas em CR+LF.
 *
 * Hash do registro tipo 7: SHA-256 dos campos 1 a 7 do registro concatenados com o hash
 * do registro tipo 7 anterior. Como as marcações são imutáveis, a cadeia é recalculada
 * desde o 1º NSR e sempre dá o mesmo resultado.
 *
 * ⚠ Validar com o especialista/atestado técnico antes de entregar à fiscalização:
 *   registros tipo 2 (empresa), 4 (ajuste de relógio), 5 (empregado) e 6 (eventos sensíveis)
 *   ainda não são gravados com NSR próprio — ver README.
 */
class AfdService
{
    public const LAYOUT_VERSION = '003';

    /** Identificador do coletor (campo 6 do tipo 7): 01 = aplicativo mobile. */
    public const COLLECTOR_MOBILE_APP = '01';

    /**
     * @return array{content: string, records: int, first_nsr: ?int, last_nsr: ?int}
     */
    public function generate(Company $company, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $company->timezone ?: config('app.timezone');
        $from = Carbon::parse($from->format('Y-m-d'), $tz)->startOfDay();
        $to = Carbon::parse($to->format('Y-m-d'), $tz)->endOfDay();

        $lines = [$this->header($company, $from, $to, $tz)];
        $count7 = 0;
        $firstNsr = $lastNsr = null;
        $prevHash = '';

        // Percorre a cadeia inteira em ordem de NSR (hash depende do anterior), mas só emite o período.
        $rows = DB::table('time_entries as t')
            ->join('employees as e', 'e.id', '=', 't.employee_id')
            ->where('t.company_id', $company->id)
            ->where('t.marked_at', '<=', $to)
            ->select('t.nsr', 't.marked_at', 't.created_at', 'e.cpf')
            ->lazyById(1000, 't.nsr', 'nsr');

        foreach ($rows as $row) {
            $fields = $this->punchFields($row, $tz);
            $hash = hash('sha256', $fields.$prevHash);
            $prevHash = $hash;

            $markedAt = Carbon::parse($row->marked_at)->setTimezone($tz);
            if ($markedAt->lt($from)) {
                continue;
            }

            $lines[] = $fields.$hash;
            $count7++;
            $firstNsr ??= (int) $row->nsr;
            $lastNsr = (int) $row->nsr;
        }

        $lines[] = $this->trailer(type7: $count7);
        $lines[] = $this->text('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', 100);

        $content = implode("\r\n", $lines)."\r\n";

        return [
            'content' => mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'),
            'records' => $count7,
            'first_nsr' => $firstNsr,
            'last_nsr' => $lastNsr,
        ];
    }

    /** Tipo 1 — cabeçalho (302 posições). */
    private function header(Company $company, CarbonInterface $from, CarbonInterface $to, string $tz): string
    {
        $employerDoc = $this->digits($company->cnpj);
        $developerDoc = $this->digits((string) config('ponto.afd.developer_document'));

        $line = '000000000'                                           // 001-009 "000000000"
            .'1'                                                      // 010     tipo do registro
            .(strlen($employerDoc) === 11 ? '2' : '1')                // 011     1 = CNPJ, 2 = CPF
            .$this->num($employerDoc, 14)                             // 012-025 CNPJ/CPF do empregador
            .$this->num('', 14)                                       // 026-039 CNO ou CAEPF (não usado)
            .$this->text($company->legal_name, 150)                   // 040-189 razão social
            .$this->text((string) config('ponto.afd.inpi_registration'), 17) // 190-206 nº de registro do REP-P no INPI
            .$from->format('Y-m-d')                                   // 207-216 data inicial
            .$to->format('Y-m-d')                                     // 217-226 data final
            .now($tz)->format('Y-m-d\TH:i:').'00'.now($tz)->format('O') // 227-250 data/hora da geração
            .self::LAYOUT_VERSION                                     // 251-253 versão do leiaute
            .(strlen($developerDoc) === 11 ? '2' : '1')               // 254     1 = CNPJ, 2 = CPF do desenvolvedor
            .$this->num($developerDoc, 14)                            // 255-268 CNPJ/CPF do desenvolvedor
            .$this->text('', 30)                                      // 269-298 modelo (só REP-C)
            .$this->text('', 4);                                      // 299-302 CRC-16 (só REP-C)

        return $line;
    }

    /** Campos 1 a 7 do tipo 7 (73 posições); o hash (campo 8, 64 posições) é anexado depois. */
    private function punchFields(object $row, string $tz): string
    {
        return $this->num((string) $row->nsr, 9)                                        // 001-009 NSR
            .'7'                                                                         // 010     tipo
            .Carbon::parse($row->marked_at)->setTimezone($tz)->format('Y-m-d\TH:i:sO')   // 011-034 data/hora da marcação
            .$this->num($this->digits($row->cpf), 12)                                   // 035-046 CPF do empregado
            .Carbon::parse($row->created_at)->setTimezone($tz)->format('Y-m-d\TH:i:sO')  // 047-070 data/hora da gravação
            .self::COLLECTOR_MOBILE_APP                                                  // 071-072 identificador do coletor
            .'0';                                                                        // 073     0 = on-line
    }

    /** Tipo 9 — trailer (64 posições). */
    private function trailer(int $type2 = 0, int $type3 = 0, int $type4 = 0, int $type5 = 0, int $type6 = 0, int $type7 = 0): string
    {
        return '999999999'
            .$this->num((string) $type2, 9)
            .$this->num((string) $type3, 9)
            .$this->num((string) $type4, 9)
            .$this->num((string) $type5, 9)
            .$this->num((string) $type6, 9)
            .$this->num((string) $type7, 9)
            .'9';
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value);
    }

    private function num(string $value, int $size): string
    {
        return str_pad(substr($value, -$size), $size, '0', STR_PAD_LEFT);
    }

    /** Alfanumérico: alinhado à esquerda, completado com espaços, sem quebrar acentos. */
    private function text(?string $value, int $size): string
    {
        $value = mb_substr(trim(preg_replace('/\s+/', ' ', (string) $value)), 0, $size);

        return $value.str_repeat(' ', $size - mb_strlen($value));
    }
}
