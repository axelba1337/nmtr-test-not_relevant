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
    private const LOCK_KEY = 'chatbot:knowledge-rebuild';

    private const LOCK_SECONDS = 1800;

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
                'Proses rebuild knowledge lain masih berjalan.',
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
                'Embedding belum dapat dibuat. Silakan coba kembali.',
            );
        } finally {
            $lock->release();
        }

        if ($status === KnowledgeEmbeddingService::STATUS_FAILED) {
            return back()->with(
                'warning',
                'Embedding baru gagal dibuat. Embedding lama tetap disimpan.',
            );
        }

        if ($status === KnowledgeEmbeddingService::STATUS_SKIPPED) {
            return back()->with(
                'warning',
                'Rebuild dilewati karena FAQ berubah atau sudah tidak tersedia.',
            );
        }

        return back()->with('success', 'Embedding FAQ berhasil dibuat ulang.');
    }

    public function rebuildAll(Request $request): RedirectResponse
    {
        $this->ensureAdmin($request);
        $lock = $this->acquireLock();

        if ($lock === null) {
            return back()->with(
                'warning',
                'Proses rebuild knowledge lain masih berjalan.',
            );
        }

        try {
            $counts = $this->embeddingService->rebuildActive(true);
        } catch (Throwable) {
            return back()->with(
                'error',
                'Knowledge belum dapat dibangun ulang. Silakan coba kembali.',
            );
        } finally {
            $lock->release();
        }

        $message = sprintf(
            'Rebuild selesai: %d berhasil, %d gagal, %d dilewati.',
            $counts[KnowledgeEmbeddingService::STATUS_PROCESSED],
            $counts[KnowledgeEmbeddingService::STATUS_FAILED],
            $counts[KnowledgeEmbeddingService::STATUS_SKIPPED],
        );

        return back()->with(
            $counts[KnowledgeEmbeddingService::STATUS_FAILED] > 0
                ? 'warning'
                : 'success',
            $message,
        );
    }

    private function acquireLock(): ?Lock
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        return $lock->get() ? $lock : null;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
