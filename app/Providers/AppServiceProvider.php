<?php

namespace App\Providers;

use App\Contracts\AI\ChatClient;
use App\Contracts\AI\EmbeddingClient;
use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Models\User;
use App\Services\AI\GeminiChatAdapter;
use App\Services\AI\GeminiEmbeddingAdapter;
use App\Services\Marketplace\GeminiListingDiscoveryProvider;
use App\Services\Search\SearchExecutionBudget;
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

        $this->app->scoped(
            SearchExecutionBudget::class,
            static fn (): SearchExecutionBudget => new SearchExecutionBudget,
        );

        $this->app->bind(
            ListingDiscoveryProvider::class,
            GeminiListingDiscoveryProvider::class,
        );

        $this->app->bind(ChatClient::class, function ($app): ChatClient {
            $provider = strtolower((string) config('ai.chat.provider'));

            return match ($provider) {
                'gemini' => $app->make(GeminiChatAdapter::class),
                default => throw new \LogicException(
                    "Chat provider tidak didukung: {$provider}",
                ),
            };
        });

        $this->app->bind(
            EmbeddingClient::class,
            function ($app): EmbeddingClient {
                $provider = strtolower((string) config(
                    'ai.embedding.provider',
                ));

                return match ($provider) {
                    'gemini' => $app->make(GeminiEmbeddingAdapter::class),
                    default => throw new \LogicException(
                        "Embedding provider tidak didukung: {$provider}",
                    ),
                };
            },
        );
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
