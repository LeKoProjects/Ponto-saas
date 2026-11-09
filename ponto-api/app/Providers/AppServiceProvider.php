<?php

namespace App\Providers;

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
    }
}
