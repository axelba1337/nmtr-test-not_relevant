<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUnansweredQueryRequest;
use App\Models\ChatbotQueryLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class UnansweredQueryController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'review' => ['nullable', 'in:all,pending,reviewed'],
            'reason' => ['nullable', 'string', 'max:64'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $review = (string) ($filters['review'] ?? 'pending');
        $reason = trim((string) ($filters['reason'] ?? ''));

        $queries = ChatbotQueryLog::query()
            ->where('is_unanswered', true)
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('query_text', 'like', "%{$search}%")
                    ->orWhere('normalized_query', 'like', "%{$search}%")))
            ->when($review === 'pending', fn (Builder $query): Builder => $query
                ->whereNull('reviewed_at'))
            ->when($review === 'reviewed', fn (Builder $query): Builder => $query
                ->whereNotNull('reviewed_at'))
            ->when($reason !== '', fn (Builder $query): Builder => $query
                ->where('unanswered_reason', $reason))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $reasons = ChatbotQueryLog::query()
            ->where('is_unanswered', true)
            ->whereNotNull('unanswered_reason')
            ->distinct()
            ->orderBy('unanswered_reason')
            ->pluck('unanswered_reason');

        return view('admin.unanswered.index', compact(
            'queries',
            'reasons',
            'search',
            'review',
            'reason',
        ));
    }

    public function show(Request $request, ChatbotQueryLog $queryLog): View
    {
        $this->ensureAdmin($request);
        abort_unless($queryLog->is_unanswered, 404);

        return view('admin.unanswered.show', [
            'queryLog' => $queryLog,
        ]);
    }

    public function update(
        UpdateUnansweredQueryRequest $request,
        ChatbotQueryLog $queryLog,
    ): RedirectResponse {
        abort_unless($queryLog->is_unanswered, 404);
        $reviewed = (bool) $request->validated('reviewed');

        $queryLog->forceFill([
            'reviewed_at' => $reviewed ? now() : null,
            'reviewed_by' => $reviewed ? $request->user()->id : null,
            'review_notes' => $request->validated('review_notes'),
        ])->save();

        return redirect()
            ->route('admin.unanswered.show', $queryLog)
            ->with(
                'success',
                $reviewed
                    ? 'Query ditandai sudah ditinjau.'
                    : 'Query dikembalikan ke antrean peninjauan.',
            );
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
