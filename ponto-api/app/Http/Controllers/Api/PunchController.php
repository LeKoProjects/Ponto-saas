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

    /** Bater ponto. A hora vem do servidor; o cliente só envia GPS opcional. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);

        $employee = $request->user()->employee;

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
