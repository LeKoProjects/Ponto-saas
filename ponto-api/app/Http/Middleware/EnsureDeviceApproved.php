<?php

namespace App\Http\Middleware;

use App\Support\ContextCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * O app só bate ponto (e acessa os dados) a partir do aparelho liberado pelo RH.
 * Respostas 403 trazem "code" para o app mostrar a tela certa:
 *   device_required  — token antigo, sem aparelho: fazer login de novo;
 *   device_pendente  — aguardando liberação do RH.
 */
class EnsureDeviceApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();
        $deviceId = $token instanceof PersonalAccessToken ? $token->device_id : null;

        if (! $deviceId) {
            return response()->json([
                'code' => 'device_required',
                'message' => 'Faça login novamente para registrar este aparelho.',
            ], 403);
        }

        $device = ContextCache::device($deviceId);

        if (! $device || $device['status'] !== 'aprovado') {
            return response()->json([
                'code' => 'device_'.($device['status'] ?? 'required'),
                'message' => 'Este aparelho ainda não foi liberado pelo RH para registrar o ponto.',
                'device' => $device['api'] ?? null,
            ], 403);
        }

        // Último acesso: grava no máximo a cada 5 min (sem disparar eventos/invalidar cache).
        if (Cache::add("ponto:device-seen:{$deviceId}", true, 300)) {
            DB::table('devices')->where('id', $deviceId)->update(['last_seen_at' => now(), 'last_ip' => $request->ip()]);
        }

        $request->attributes->set('device_id', $deviceId);

        return $next($request);
    }
}
