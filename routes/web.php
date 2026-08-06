<?php

use App\Http\Controllers\Admin\AuthenticatedSessionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeEmbeddingController;
use App\Http\Controllers\Admin\KnowledgeItemController;
use App\Http\Controllers\Admin\ListingController;
use App\Http\Controllers\Admin\PlatformController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\RankingProfileController;
use App\Http\Controllers\Admin\UnansweredQueryController;
use App\Http\Controllers\ListingRedirectController;
use App\Http\Controllers\ProductComparisonController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('chatbot.widget'))
    ->name('chatbot');

Route::get('/products/{product}/compare', ProductComparisonController::class)
    ->name('products.compare');
Route::get('/listings/{listing}/open', ListingRedirectController::class)
    ->name('listings.open');

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

            Route::resource('categories', CategoryController::class)
                ->except('show');
            Route::resource('products', ProductController::class)
                ->except('show');
            Route::post(
                '/platforms/{platform}/sync',
                [PlatformController::class, 'sync'],
            )->name('platforms.sync');
            Route::resource('platforms', PlatformController::class)
                ->except('show');
            Route::resource('listings', ListingController::class)
                ->except('show');

            Route::get(
                '/unanswered-queries',
                [UnansweredQueryController::class, 'index'],
            )->name('unanswered.index');
            Route::get(
                '/unanswered-queries/{queryLog}',
                [UnansweredQueryController::class, 'show'],
            )->name('unanswered.show');
            Route::patch(
                '/unanswered-queries/{queryLog}',
                [UnansweredQueryController::class, 'update'],
            )->name('unanswered.update');
            Route::get(
                '/ranking',
                [RankingProfileController::class, 'edit'],
            )->name('ranking.edit');
            Route::patch(
                '/ranking',
                [RankingProfileController::class, 'update'],
            )->name('ranking.update');

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
