<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'E-mail ou senha inválidos.']);
        }

        if (! $user->employee || ! $user->employee->active) {
            throw ValidationException::withMessages(['email' => 'Usuário sem cadastro de funcionário ativo.']);
        }

        if (! $user->company?->isActive()) {
            throw ValidationException::withMessages(['email' => 'Empresa bloqueada. Entre em contato com o suporte.']);
        }

        $token = $user->createToken($data['device_name'] ?? 'pwa', ['ponto'])->plainTextToken;

        return response()->json(['token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request, TimeEntryService $service): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee()->with('workSchedule', 'company')->firstOrFail();

        $today = TimeEntry::where('employee_id', $employee->id)
            ->whereBetween('marked_at', [now()->startOfDay(), now()->endOfDay()])
            ->orderBy('marked_at')
            ->get();

        return response()->json([
            'user' => ['name' => $user->name, 'email' => $user->email],
            'company' => $employee->company->displayName(),
            'schedule' => [
                'name' => $employee->workSchedule->name,
                'start' => substr($employee->workSchedule->start_time, 0, 5),
                'end' => substr($employee->workSchedule->end_time, 0, 5),
            ],
            'server_time' => now()->toIso8601String(),
            'next_type' => $service->nextType($employee),
            'next_type_label' => TimeEntry::TYPES[$service->nextType($employee)],
            'today' => TimeEntryResource::collection($today),
        ]);
    }
}
