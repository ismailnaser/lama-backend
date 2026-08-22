<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(8)->by($request->ip() ?: 'login');
        });

        RateLimiter::for('scan', function (Request $request) {
            $userId = $request->attributes->get('auth_user')?->id;
            return Limit::perMinute(6)->by($userId ? 'scan:'.$userId : 'scan:'.($request->ip() ?: 'anon'));
        });
    }
}
