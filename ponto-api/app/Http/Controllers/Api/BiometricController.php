<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\BiometricService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Biometria do celular (WebAuthn) do aparelho liberado. */
class BiometricController extends Controller
{
    public function __construct(private BiometricService $biometric) {}

    /** Opções para o celular criar a credencial. */
    public function registrationOptions(Request $request): JsonResponse
    {
        return response()->json($this->biometric->registrationOptions($this->device($request), $request->user()));
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'clientDataJSON' => ['required', 'string', 'max:4096'],
            'attestationObject' => ['required', 'string', 'max:16384'],
        ]);
        $device = $this->biometric->register($this->device($request), $request->user(), $data);

        return response()->json(['device' => $device->fresh()->toApiArray()]);
    }

    /** Desafio de uso único para confirmar a batida. */
    public function challenge(Request $request): JsonResponse
    {
        return response()->json($this->biometric->assertionOptions($this->device($request)));
    }

    public function disable(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $this->biometric->disable($device, $request->user());

        return response()->json(['device' => $device->fresh()->toApiArray()]);
    }

    private function device(Request $request): Device
    {
        return Device::findOrFail($request->attributes->get('device_id'));
    }
}
