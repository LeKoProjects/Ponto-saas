<?php

namespace App\Http\Middleware;

use App\Models\Device;
use App\Models\TimeAdjustment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'role_label' => User::ROLES[$user->role] ?? $user->role,
                    'can_manage' => $user->canManage(),
                ] : null,
                'company' => $user?->company ? [
                    'id' => $user->company->id,
                    'name' => $user->company->displayName(),
                    'plan' => $user->company->plan,
                    'logo_url' => $user->company->logoUrl(),
                ] : null,
            ],
            // Badge do menu "Ajustes": solicitações aguardando decisão.
            'pending_adjustments' => fn () => $user?->company_id && $user->hasRole('owner', 'rh', 'gestor')
                ? TimeAdjustment::forCompany($user->company_id)
                    ->where('status', 'pendente')
                    ->when($user->hasRole('gestor'), fn ($q) => $q->whereHas('employee.team', fn ($t) => $t->where('manager_user_id', $user->id)))
                    ->count()
                : 0,
            // Badge do menu "Aparelhos": aparelhos aguardando liberação (só dono/RH).
            'pending_devices' => fn () => $user?->company_id && $user->canManage()
                ? Device::forCompany($user->company_id)->where('status', 'pendente')->count()
                : 0,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
