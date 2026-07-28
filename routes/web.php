<?php

use App\Http\Controllers\Admin\AuthenticatedSessionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeEmbeddingController;
use App\Http\Controllers\Admin\KnowledgeItemController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('chatbot.widget'))
    ->name('chatbot');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [
            AuthenticatedSessionController::class,
            'create',
        ])->name('login');
        Route::post('/login', [
            AuthenticatedSessionController::class,
            'store',
        ])->middleware('throttle:admin-login')->name('login.store');
    });

    Route::middleware(['auth', 'can:access-admin'])
        ->group(function (): void {
            Route::post('/logout', [
                AuthenticatedSessionController::class,
                'destroy',
            ])->name('logout');

            Route::get('/', DashboardController::class)
                ->name('dashboard');

            Route::patch(
                '/knowledge/{knowledgeItem}/active',
                [KnowledgeItemController::class, 'toggleActive'],
            )->name('knowledge.toggle-active');
            Route::patch(
                '/knowledge/{knowledgeItem}/featured',
                [KnowledgeItemController::class, 'toggleFeatured'],
            )->name('knowledge.toggle-featured');
            Route::post(
                '/knowledge/{knowledgeItem}/embedding/rebuild',
                [KnowledgeEmbeddingController::class, 'rebuildOne'],
            )->name('knowledge.embedding.rebuild');
            Route::post(
                '/knowledge/rebuild',
                [KnowledgeEmbeddingController::class, 'rebuildAll'],
            )->name('knowledge.rebuild');

            Route::resource('knowledge', KnowledgeItemController::class)
                ->except('show')
                ->parameters(['knowledge' => 'knowledgeItem']);
        });
});
