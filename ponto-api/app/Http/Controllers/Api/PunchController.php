<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Services\MonthlyReportService;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PunchController extends Controller
{
    public function __construct(private TimeEntryService $service) {}

    /**
     * Bater ponto. A hora vem do servidor.
     * Localização: o RH define na empresa. Ativa → obrigatória; inativa → não é coletada.
     */
    public function store(Request $request): JsonResponse
    {
        $employee = $request->user()->employee;
        $requireLocation = (bool) $employee->company->require_location;

        $data = $request->validate([
            'latitude' => [$requireLocation ? 'required' : 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => [$requireLocation ? 'required' : 'nullable', 'numeric', 'between:-180,180'],
        ], [
            'latitude.required' => 'Sua empresa exige a localização para registrar o ponto. Ative o GPS e permita o acesso à localização.',
            'longitude.required' => 'Sua empresa exige a localização para registrar o ponto. Ative o GPS e permita o acesso à localização.',
        ]);

        if (! $requireLocation) {
            $data = []; // LGPD: não guardar localização que a empresa não exige.
        }

        $entry = $this->service->register($employee, [
            ...$data,
            'ip' => $request->ip(),
            'device' => $request->userAgent(),
        ]);

        return response()->json([
            'entry' => new TimeEntryResource($entry),
            'receipt' => $this->service->receipt($entry),
            'next_type' => $this->service->nextType($employee),
        ], 201);
    }

    public function receipt(Request $request, TimeEntry $entry): JsonResponse
    {
        abort_unless($entry->employee_id === $request->user()->employee?->id, 404);

        return response()->json($this->service->receipt($entry));
    }

    public function history(Request $request, MonthlyReportService $reports): JsonResponse
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        [$year, $month] = array_map('intval', explode('-', $data['month'] ?? now()->format('Y-m')));

        return response()->json($reports->forEmployee($request->user()->employee, $year, $month));
    }
}
