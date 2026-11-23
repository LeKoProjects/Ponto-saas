<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #222; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .muted { color: #666; }
        .summary { margin: 8px 0; }
        .summary td { border: 1px solid #ddd; padding: 4px 6px; }
        .summary b { font-size: 11px; display: block; }
        table.data { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.data th, table.data td { border: 1px solid #bbb; padding: 3px 4px; text-align: left; vertical-align: top; }
        table.data th { background: #eee; }
        .danger { color: #b02a37; font-weight: bold; }
        .warning { color: #997404; font-weight: bold; }
        .success { color: #146c43; }
        .info, .secondary { color: #555; }
        .notes { margin-top: 10px; color: #555; }
        .notes li { margin-bottom: 2px; }
        .footer { margin-top: 12px; color: #888; font-size: 7.5px; }
    </style>
</head>
<body>
    @if ($logo = $company->logoDataUri())
        <img src="{{ $logo }}" style="max-height:36px;max-width:150px;float:right" alt="">
    @endif
    <h1>{{ $r['title'] }} — {{ $r['mode'] === 'geral' ? 'Geral' : 'Individual' }}</h1>
    <div class="muted">{{ $company->legal_name }} — CNPJ {{ $company->cnpj }}</div>
    <div>{{ $r['subtitle'] }}</div>

    @if ($r['summary'])
        <table class="summary"><tr>
            @foreach ($r['summary'] as $s)
                <td><span class="muted">{{ $s['label'] }}</span><b class="{{ $s['tone'] ?? '' }}">{{ $s['value'] }}</b></td>
            @endforeach
        </tr></table>
    @endif

    <table class="data">
        <thead><tr>@foreach ($r['columns'] as $c)<th>{{ $c['label'] }}</th>@endforeach</tr></thead>
        <tbody>
        @forelse ($r['rows'] as $row)
            <tr>
                @foreach ($r['columns'] as $c)
                    @php $cell = $row['cells'][$c['key']] ?? ''; @endphp
                    @if (is_array($cell) && isset($cell['badges']))
                        <td>@foreach ($cell['badges'] as $b)<span class="{{ $b['tone'] }}">{{ $b['text'] }}</span>@if (! $loop->last), @endif @endforeach</td>
                    @elseif (is_array($cell))
                        <td class="{{ $cell['tone'] ?? '' }}">{{ $cell['text'] }}</td>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($r['columns']) }}" class="muted">Nada a mostrar no período.</td></tr>
        @endforelse
        </tbody>
    </table>

    @if ($r['notes'])
        <ul class="notes">@foreach ($r['notes'] as $n)<li>{{ $n }}</li>@endforeach</ul>
    @endif
    <div class="footer">Gerado em {{ now()->format('d/m/Y H:i') }} · Horas no formato HH:MM · Ajustes aprovados já aplicados.</div>
</body>
</html>
