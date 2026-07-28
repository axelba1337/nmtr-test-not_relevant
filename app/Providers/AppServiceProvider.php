<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        config(['filesystems.disks.local.serve' => false]);
    }

    public function boot(): void
    {
        Gate::define(
            'access-admin',
            static fn (User $user): bool => $user->is_admin,
        );

        RateLimiter::for(
            'chatbot',
            static fn (Request $request): Limit => Limit::perMinute(
                max(1, (int) config('chatbot.rate_limit', 20)),
            )->by((string) $request->ip()),
        );

        RateLimiter::for(
            'admin-login',
            static fn (Request $request): Limit => Limit::perMinute(5)->by(
                Str::lower((string) $request->input('email'))
                    .'|'.$request->ip(),
            ),
        );
    }
}
