<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustmentRequest;
use App\Services\AdjustmentService;
use App\Services\MonthlyReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Colaborador: solicitar ajustes e baixar o próprio espelho. */
class AdjustmentController extends Controller
{
    public function store(AdjustmentRequest $request, AdjustmentService $service): JsonResponse
    {
        $user = $request->user();
        $adjustment = $service->create($user->employee, $user, $request->validated(), autoApprove: false);
        $adjustment->load('timeEntry:id,marked_at');

        return response()->json([
            'message' => 'Solicitação enviada. Ela vale depois que o RH ou seu gestor aprovar.',
            'request' => $adjustment->toApiArray(),
        ], 201);
    }

    public function pdf(Request $request, MonthlyReportService $reports): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        [$year, $month] = array_map('intval', explode('-', $data['month'] ?? now()->format('Y-m')));

        return $reports->pdf($request->user()->employee, $year, $month)
            ->download(sprintf('espelho-%04d-%02d.pdf', $year, $month));
    }
}
