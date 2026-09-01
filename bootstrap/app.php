<?php

use App\Console\Commands\CreateAdmin;
use App\Console\Commands\EmbedKnowledge;
use App\Console\Commands\ImportMarketplaceListings;
use App\Console\Commands\ProbeMarketplace;
use App\Console\Commands\RebuildNeomatraEmbeddings;
use App\Console\Commands\SyncMarketplace;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);
        $middleware->redirectGuestsTo(
            static fn (Request $request): string => route('admin.login'),
        );
        $middleware->redirectUsersTo(
            static fn (Request $request): string => route('admin.dashboard'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withCommands([
        CreateAdmin::class,
        EmbedKnowledge::class,
        ImportMarketplaceListings::class,
        ProbeMarketplace::class,
        RebuildNeomatraEmbeddings::class,
        SyncMarketplace::class,
    ])
    ->create();
