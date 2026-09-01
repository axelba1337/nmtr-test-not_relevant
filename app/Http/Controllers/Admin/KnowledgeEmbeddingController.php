<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class KnowledgeEmbeddingController extends Controller
{
    public function __construct(
        private readonly KnowledgeEmbeddingService $embeddingService,
    ) {}

    public function rebuildOne(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $lock = $this->acquireLock();

        if ($lock === null) {
            return back()->with(
                'warning',
                __('ui.messages.admin.embedding.locked'),
            );
        }

        try {
            $status = $this->embeddingService->rebuild(
                $knowledgeItem,
                true,
            );
        } catch (Throwable) {
            return back()->with(
                'error',
                __('ui.messages.admin.embedding.single_error'),
            );
        } finally {
            $lock->release();
        }

        if ($status === KnowledgeEmbeddingService::STATUS_FAILED) {
            return back()->with(
                'warning',
                __('ui.messages.admin.embedding.failed_preserved'),
            );
        }

        if ($status === KnowledgeEmbeddingService::STATUS_SKIPPED) {
            return back()->with(
                'warning',
                __('ui.messages.admin.embedding.skipped'),
            );
        }

        return back()->with(
            'success',
            __('ui.messages.admin.embedding.single_success'),
        );
    }

    public function rebuildAll(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);
        $lock = $this->acquireLock();

        if ($lock === null) {
            return back()->with(
                'warning',
                __('ui.messages.admin.embedding.locked'),
            );
        }

        try {
            $counts = $this->embeddingService->rebuildActive(true);
        } catch (Throwable) {
            return back()->with(
                'error',
                __('ui.messages.admin.embedding.all_error'),
            );
        } finally {
            $lock->release();
        }

        $message = __('ui.messages.admin.embedding.completed', [
            'processed' => $counts[KnowledgeEmbeddingService::STATUS_PROCESSED],
            'failed' => $counts[KnowledgeEmbeddingService::STATUS_FAILED],
            'skipped' => $counts[KnowledgeEmbeddingService::STATUS_SKIPPED],
        ]);

        return back()->with(
            $counts[KnowledgeEmbeddingService::STATUS_FAILED] > 0
                ? 'warning'
                : 'success',
            $message,
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
