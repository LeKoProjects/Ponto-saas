<?php

namespace App\Providers;

use App\Models\Company;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\ContextCache;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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

        // Invalida o cache de contexto da batida quando os dados mudam.
        $user = fn (User $u) => ContextCache::forgetUser($u->id);
        User::saved($user);
        User::deleted($user);

        $employee = fn (Employee $e) => ContextCache::forgetUser($e->user_id);
        Employee::saved($employee);
        Employee::deleted($employee);

        Company::saved(fn (Company $c) => ContextCache::forgetCompany($c->id));
        WorkSchedule::saved(fn (WorkSchedule $s) => ContextCache::forgetSchedule($s->id));
        Device::saved(fn (Device $d) => ContextCache::forgetDevice($d->id));
    }
}
