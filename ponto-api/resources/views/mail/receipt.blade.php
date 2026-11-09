<!doctype html>
<html lang="pt-BR">
<body style="font-family: Arial, sans-serif; color:#222">
    <h2 style="margin-bottom:4px">Comprovante de Registro de Ponto do Trabalhador</h2>
    <p style="margin-top:0;color:#666">REP-P — Portaria MTP nº 671/2021</p>
    <table cellpadding="6" style="border-collapse:collapse">
        <tr><td><b>Empregador</b></td><td>{{ $r['empregador'] }}</td></tr>
        <tr><td><b>CNPJ</b></td><td>{{ $r['cnpj'] }}</td></tr>
        <tr><td><b>Trabalhador</b></td><td>{{ $r['trabalhador'] }}</td></tr>
        <tr><td><b>CPF</b></td><td>{{ $r['cpf'] }}</td></tr>
        <tr><td><b>Data</b></td><td>{{ $r['data'] }}</td></tr>
        <tr><td><b>Hora</b></td><td>{{ $r['hora'] }} (UTC{{ $r['fuso'] }})</td></tr>
        <tr><td><b>Tipo</b></td><td>{{ $r['tipo'] }}</td></tr>
        <tr><td><b>NSR</b></td><td>{{ str_pad($r['nsr'], 9, '0', STR_PAD_LEFT) }}</td></tr>
        <tr><td><b>Hash (SHA-256)</b></td><td style="font-family:monospace;font-size:11px">{{ $r['hash'] }}</td></tr>
    </table>
</body>
</html>
