<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;
use App\Support\ContextCache;
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
        $ctx = ContextCache::employee($request->user());
        abort_unless($ctx, 404, 'Usuário sem cadastro de funcionário.');

        $today = $service->today($ctx['company_id'], $ctx['employee_id']);
        $next = TimeEntryService::nextTypeAfter($today->count());

        return response()->json([
            // Perfil: o app guarda localmente e exibe na hora, antes mesmo desta resposta.
            'profile' => [
                'employee_id' => $ctx['employee_id'],
                'name' => $ctx['name'],
                'email' => $ctx['email'],
                'cpf' => $ctx['cpf'],
                'company' => $ctx['company_name'],
                'cnpj' => $ctx['cnpj'],
                'schedule' => $ctx['schedule'],
                'require_location' => $ctx['require_location'],
            ],
            // Campos antigos mantidos por compatibilidade com versões do app em cache.
            'user' => ['name' => $ctx['name'], 'email' => $ctx['email']],
            'company' => $ctx['company_name'],
            'schedule' => $ctx['schedule'],
            'require_location' => $ctx['require_location'],
            'server_time' => now()->toIso8601String(),
            'next_type' => $next,
            'next_type_label' => TimeEntry::TYPES[$next],
            'today' => TimeEntryResource::collection($today),
        ]);
    }
}
