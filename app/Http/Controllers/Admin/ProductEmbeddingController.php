<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Embedding\EntityEmbeddingService;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProductEmbeddingController extends Controller
{
    public function __construct(
        private readonly EntityEmbeddingService $embeddingService,
    ) {}

    public function rebuildOne(
        Request $request,
        Product $product,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $lock = $this->acquireLock();

        if ($lock === null) {
            return back()->with(
                'warning',
                __('ui.messages.admin.product_embedding.locked'),
            );
        }

        try {
            $counts = $this->embeddingService->rebuildProductTree(
                $product,
                false,
            );
        } catch (Throwable) {
            return back()->with(
                'error',
                __('ui.messages.admin.product_embedding.error'),
            );
        } finally {
            $lock->release();
        }

        if ($counts[EntityEmbeddingService::FAILED] > 0) {
            return back()->with(
                'warning',
                __('ui.messages.admin.product_embedding.partial_failure'),
            );
        }

        return back()->with(
            'success',
            $counts[EntityEmbeddingService::PROCESSED] > 0
                ? __('ui.messages.admin.product_embedding.single_success')
                : __('ui.messages.admin.product_embedding.current'),
        );
    }

    private function acquireLock(): ?Lock
    {
        $lock = Cache::lock(
            KnowledgeEmbeddingService::LOCK_KEY,
            KnowledgeEmbeddingService::LOCK_SECONDS,
        );

        return $lock->get() ? $lock : null;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
