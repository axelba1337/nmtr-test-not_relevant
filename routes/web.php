<?php

use App\Http\Controllers\Admin\AuthenticatedSessionController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KnowledgeEmbeddingController;
use App\Http\Controllers\Admin\KnowledgeItemController;
use App\Http\Controllers\Admin\ListingController;
use App\Http\Controllers\Admin\ListingCoverageController;
use App\Http\Controllers\Admin\ListingDiscoveryController;
use App\Http\Controllers\Admin\ListingImportController;
use App\Http\Controllers\Admin\PlatformController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductEmbeddingController;
use App\Http\Controllers\Admin\UnansweredQueryController;
use App\Http\Controllers\Admin\UnmatchedListingController;
use App\Http\Controllers\ListingRedirectController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('chatbot.widget'))
    ->name('chatbot');

Route::post('/locale/{locale}', LocaleController::class)
    ->where('locale', '[A-Za-z_-]+')
    ->name('locale.update');

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

            Route::get('/categories/export', [
                CategoryController::class,
                'export',
            ])->name('categories.export');
            Route::resource('categories', CategoryController::class)
                ->except('show');
            Route::get('/products/export', [
                ProductController::class,
                'export',
            ])->name('products.export');
            Route::post(
                '/products/{product}/embedding/rebuild',
                [ProductEmbeddingController::class, 'rebuildOne'],
            )->name('products.embedding.rebuild');
            Route::resource('products', ProductController::class)
                ->except('show');
            Route::get('/platforms/export', [
                PlatformController::class,
                'export',
            ])->name('platforms.export');
            Route::resource('platforms', PlatformController::class)
                ->only(['index', 'edit', 'update']);
            Route::get('/listings/export', [
                ListingController::class,
                'export',
            ])->name('listings.export');
            Route::get('/listings/import', [
                ListingImportController::class,
                'create',
            ])->name('listings.import');
            Route::post('/listings/import/preview', [
                ListingImportController::class,
                'preview',
            ])->name('listings.import.preview');
            Route::post('/listings/import/confirm', [
                ListingImportController::class,
                'import',
            ])->name('listings.import.confirm');
            Route::get('/listings/import/template', [
                ListingImportController::class,
                'template',
            ])->name('listings.import.template');
            Route::get('/listings/coverage', [
                ListingCoverageController::class,
                'index',
            ])->name('listings.coverage');
            Route::get('/listings/coverage/export', [
                ListingCoverageController::class,
                'export',
            ])->name('listings.coverage.export');
            Route::get('/listings/coverage/research-prompt', [
                ListingCoverageController::class,
                'researchPrompt',
            ])->name('listings.coverage.research-prompt');
            Route::get('/listings/automatic', [
                ListingDiscoveryController::class,
                'index',
            ])->name('listings.discovery');
            Route::post('/listings/automatic/start', [
                ListingDiscoveryController::class,
                'start',
            ])->name('listings.discovery.start');
            Route::post(
                '/listings/automatic/candidates/{candidate}/approve',
                [ListingDiscoveryController::class, 'approve'],
            )->name('listings.discovery.candidates.approve');
            Route::post(
                '/listings/automatic/candidates/{candidate}/reject',
                [ListingDiscoveryController::class, 'reject'],
            )->name('listings.discovery.candidates.reject');
            Route::get(
                '/listings/unmatched',
                [ListingController::class, 'unmatched'],
            )->name('listings.unmatched');
            Route::get(
                '/listings/{listing}/candidate',
                [UnmatchedListingController::class, 'review'],
            )->name('listings.candidate.review');
            Route::post(
                '/listings/{listing}/candidate',
                [UnmatchedListingController::class, 'approve'],
            )->name('listings.candidate.approve');
            Route::post(
                '/listings/{listing}/link-product',
                [UnmatchedListingController::class, 'link'],
            )->name('listings.link-product');
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
