<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Services\MonthlyReportService;
use App\Services\TimeEntryService;
use App\Support\ContextCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PunchController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    /**
     * Bater ponto. A hora vem do servidor.
     * Dados do colaborador/empresa vêm do cache de contexto (sem recarregar a cada toque).
     * Localização: o RH define na empresa. Ativa → obrigatória; inativa → não é coletada.
     * Resposta já traz tudo que a tela precisa (comprovante, marcações de hoje, próximo tipo).
     */
    public function store(Request $request): JsonResponse
    {
        $ctx = ContextCache::employee($request->user());
        abort_unless($ctx && $ctx['active'], 403, 'Usuário sem cadastro de funcionário ativo.');

        $requireLocation = $ctx['require_location'];
        $locationMessage = 'Sua empresa exige a localização para registrar o ponto. Ative o GPS e permita o acesso à localização.';

        $data = $request->validate([
            'latitude' => [$requireLocation ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$requireLocation ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
            'request_id' => ['nullable', 'uuid'],
        ], [
            'latitude.required' => $locationMessage,
            'longitude.required' => $locationMessage,
        ]);

        $meta = [
            'request_id' => $data['request_id'] ?? null,
            'ip' => $request->ip(),
            'device' => $request->userAgent(),
        ];
        if ($requireLocation) {
            // LGPD: só guarda localização quando a empresa exige.
            $meta['latitude'] = $data['latitude'];
            $meta['longitude'] = $data['longitude'];
        }

        ['entry' => $entry, 'replayed' => $replayed] = $this->service->registerFor($ctx, $meta);

        $today = $this->service->today($ctx['company_id'], $ctx['employee_id']);
        $next = TimeEntryService::nextTypeAfter($today->count());

        return response()->json([
            'entry' => new TimeEntryResource($entry),
            'receipt' => $this->service->receipt($entry, $ctx),
            'today' => TimeEntryResource::collection($today),
            'next_type' => $next,
            'next_type_label' => TimeEntry::TYPES[$next],
            'replayed' => $replayed,
        ], $replayed ? 200 : 201);
    }

    public function receipt(Request $request, TimeEntry $entry): JsonResponse
    {
        $ctx = ContextCache::employee($request->user());
        abort_unless($ctx && $entry->employee_id === $ctx['employee_id'], 404);

        return response()->json($this->service->receipt($entry, $ctx));
    }

    public function history(Request $request, MonthlyReportService $reports): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        [$year, $month] = array_map('intval', explode('-', $data['month'] ?? now()->format('Y-m')));

        return response()->json($reports->forEmployee($request->user()->employee, $year, $month));
    }
}
