<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title inertia>{{ config('app.name') }}</title>
    @vite('resources/js/app.js')
    @inertiaHead
</head>
<body class="bg-body-tertiary">
    @inertia

    {{-- Modal de loading exibido em toda requisição do painel --}}
    <div class="modal" id="loadingModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow">
                <div class="modal-body d-flex align-items-center gap-3">
                    <div class="spinner-border text-primary" role="status"></div>
                    <span>Carregando…</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
