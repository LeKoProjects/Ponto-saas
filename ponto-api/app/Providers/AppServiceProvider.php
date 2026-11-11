<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\ContextCache;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Tenant::class);
    }

    public function boot(): void
    {
        // Rate limit do endpoint de marcação: evita duplo toque e abuso.
        RateLimiter::for('ponto', fn (Request $request) => Limit::perMinute(4)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Esqueci minha senha: poucos pedidos por IP + e-mail (evita spam de e-mails).
        RateLimiter::for('password', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perHour(10)->by('mail:'.strtolower((string) $request->input('email'))),
        ]);

        // Invalida o cache de contexto da batida quando os dados mudam.
        $user = fn (User $u) => ContextCache::forgetUser($u->id);
        User::saved($user);
        User::deleted($user);

        $employee = fn (Employee $e) => ContextCache::forgetUser($e->user_id);
        Employee::saved($employee);
        Employee::deleted($employee);

        // Colaborador desativado (desligamento): o app perde o acesso na hora.
        Employee::saved(function (Employee $e) {
            if (! $e->active && $e->wasChanged('active')) {
                PersonalAccessToken::where('tokenable_type', User::class)->where('tokenable_id', $e->user_id)->delete();
            }
        });

        // Token do app expira por inatividade: sem uso por N dias, o app volta ao login.
        // Cada requisição renova o prazo (Sanctum grava last_used_at), então quem usa todo dia não é deslogado.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            $lastUse = $token->last_used_at ?? $token->created_at;

            return $isValid && $lastUse && $lastUse->gt(now()->subDays(config('ponto.token_idle_days')));
        });

        Company::saved(fn (Company $c) => ContextCache::forgetCompany($c->id));
        WorkSchedule::saved(fn (WorkSchedule $s) => ContextCache::forgetSchedule($s->id));
        Device::saved(fn (Device $d) => ContextCache::forgetDevice($d->id));
        $location = fn (WorkLocation $l) => ContextCache::forgetCompany($l->company_id);
        WorkLocation::saved($location);
        WorkLocation::deleted($location);
    }
}
