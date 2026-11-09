<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TimeEntryResource;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\PasswordResetService;
use App\Services\TimeEntryService;
use App\Support\ContextCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Login do app. Sempre devolve um token ligado ao aparelho (device_id),
     * mas o token só bate ponto quando o RH libera esse aparelho.
     */
    public function login(Request $request, DeviceService $devices): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_id' => ['required', 'uuid'],
            'device_name' => ['required', 'string', 'max:160'],
        ], [
            'device_id.required' => 'Atualize o aplicativo para continuar.',
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

        $device = $devices->register($user->employee, $data['device_id'], $data['device_name'], $request->userAgent(), $request->ip());

        // Um token por aparelho: novo login no mesmo aparelho substitui o anterior.
        PersonalAccessToken::where('device_id', $device->id)->delete();
        $newToken = $user->createToken($device->name, ['ponto']);
        $newToken->accessToken->forceFill(['device_id' => $device->id])->save();

        return response()->json([
            'token' => $newToken->plainTextToken,
            'device' => $device->toApiArray(),
        ]);
    }

    /** Esqueci minha senha pelo app: o e-mail traz o link da página de nova senha. */
    public function forgotPassword(Request $request, PasswordResetService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $service->sendLink($data['email']);

        return response()->json(['message' => PasswordResetService::SENT_MESSAGE]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    /** Status do aparelho do token (o app consulta enquanto aguarda o RH). */
    public function device(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->device_id : null;
        $device = $deviceId ? ContextCache::device($deviceId) : null;

        abort_unless($device, 403, 'Faça login novamente para registrar este aparelho.');

        return response()->json(['device' => $device['api']]);
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
                'company_id' => $ctx['company_id'],
                'cnpj' => $ctx['cnpj'],
                'logo_url' => $ctx['logo_url'] ?? null,
                'schedule' => $ctx['schedule'],
                'require_location' => $ctx['require_location'],
            ],
            // Campos antigos mantidos por compatibilidade com versões do app em cache.
            'user' => ['name' => $ctx['name'], 'email' => $ctx['email']],
            'company' => $ctx['company_name'],
            'schedule' => $ctx['schedule'],
            'require_location' => $ctx['require_location'],
            'device' => ContextCache::device($request->attributes->get('device_id'))['api'] ?? null,
            'server_time' => now()->toIso8601String(),
            'next_type' => $next,
            'next_type_label' => TimeEntry::TYPES[$next],
            'today' => TimeEntryResource::collection($today),
        ]);
    }
}
