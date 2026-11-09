<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
        h1 { font-size: 14px; margin: 0 0 4px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #bbb; padding: 3px 4px; text-align: center; }
        th { background: #eee; }
        td.left { text-align: left; }
        .off { color: #888; background: #f7f7f7; }
        .strike { text-decoration: line-through; color: #a00; }
        .inc { color: #0a5; font-weight: bold; }
        .sign { margin-top: 40px; width: 100%; }
        .sign td { border: none; padding-top: 30px; }
    </style>
</head>
<body>
    <h1>Espelho de Ponto — {{ ucfirst($r['period']['label']) }}</h1>
    <div class="muted">{{ $company->legal_name }} — CNPJ {{ $company->cnpj }}</div>
    <div><b>{{ $r['employee']['name'] }}</b> · CPF {{ $r['employee']['cpf'] }} · Matrícula {{ $r['employee']['registration'] ?? '—' }} · Jornada {{ $r['employee']['schedule'] }}</div>

    <table>
        <thead>
            <tr>
                <th>Dia</th><th>Marcações</th><th>Previsto</th><th>Trabalhado</th><th>Atraso</th>
                <th>HE 50%</th><th>HE 100%</th><th>Faltante</th><th>Noturno</th><th>Obs.</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($r['days'] as $d)
            <tr class="{{ $d['is_workday'] ? '' : 'off' }}">
                <td class="left">{{ $d['label'] }}</td>
                <td class="left">
                    @foreach ($d['marks'] as $m)
                        <span class="{{ $m['discarded'] ? 'strike' : ($m['source'] !== 'original' ? 'inc' : '') }}">{{ $m['time'] }}</span>
                    @endforeach
                </td>
                <td>{{ $fmt($d['expected_minutes']) }}</td>
                <td>{{ $fmt($d['worked_minutes']) }}</td>
                <td>{{ $d['late_minutes'] ? $fmt($d['late_minutes']) : '' }}</td>
                <td>{{ $d['overtime_50_minutes'] ? $fmt($d['overtime_50_minutes']) : '' }}</td>
                <td>{{ $d['overtime_100_minutes'] ? $fmt($d['overtime_100_minutes']) : '' }}</td>
                <td>{{ $d['missing_minutes'] ? $fmt($d['missing_minutes']) : '' }}</td>
                <td>{{ $d['night_minutes'] ? $fmt($d['night_minutes']) : '' }}</td>
                <td class="left">{{ $d['holiday'] ?? ($d['absent'] ? 'Falta' : ($d['incomplete'] ? 'Incompleto' : '')) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Totais (faltas: {{ $r['totals']['absences'] }})</th>
                <th>{{ $fmt($r['totals']['expected_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['worked_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['late_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['overtime_50_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['overtime_100_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['missing_minutes']) }}</th>
                <th>{{ $fmt($r['totals']['night_minutes']) }}</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <p class="muted">Legenda: <span class="strike">riscado</span> = marcação desconsiderada por tratamento; <span class="inc">verde</span> = inclusão por tratamento. As marcações originais são preservadas (Portaria MTP 671/2021).</p>

    <table class="sign">
        <tr>
            <td>_______________________________<br>{{ $company->legal_name }}</td>
            <td>_______________________________<br>{{ $r['employee']['name'] }}</td>
        </tr>
    </table>
</body>
</html>
