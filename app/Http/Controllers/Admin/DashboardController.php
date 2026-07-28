<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless((bool) $request->user()?->is_admin, 403);

        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $activityStart = now()->subDays(6)->startOfDay();

        $todayQuestions = ChatbotQueryLog::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        $averageSimilarity = ChatbotQueryLog::query()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->whereNotNull('top1_similarity')
            ->avg('top1_similarity');

        $activeItems = KnowledgeItem::query()
            ->active()
            ->get([
                'id',
                'title',
                'content',
                'embedding',
                'embedding_hash',
                'embedding_model',
                'embedded_at',
                'embedding_error',
            ]);

        $knowledgeStats = [
            'active' => $activeItems->count(),
            'ready' => 0,
            'failed' => 0,
            'pending' => 0,
            'warnings' => 0,
        ];

        foreach ($activeItems as $item) {
            $status = $item->embeddingStatus();

            if ($item->hasCurrentEmbedding()) {
                $knowledgeStats['ready']++;
            } elseif ($status === KnowledgeItem::EMBEDDING_STATUS_FAILED) {
                $knowledgeStats['failed']++;
            } else {
                $knowledgeStats['pending']++;
            }

            if ($status === KnowledgeItem::EMBEDDING_STATUS_READY_WITH_WARNING) {
                $knowledgeStats['warnings']++;
            }
        }

        $activityByDate = ChatbotQueryLog::query()
            ->selectRaw('DATE(created_at) AS activity_date, COUNT(*) AS total')
            ->whereBetween('created_at', [$activityStart, $todayEnd])
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get()
            ->mapWithKeys(
                static fn (ChatbotQueryLog $row): array => [
                    (string) $row->getAttribute('activity_date') => (int) $row->getAttribute('total'),
                ],
            );

        $activity = [];
        $cursor = $activityStart->copy();

        for ($day = 0; $day < 7; $day++) {
            $date = $cursor->format('Y-m-d');
            $activity[] = [
                'date' => $date,
                'label' => $cursor->translatedFormat('d M'),
                'total' => (int) $activityByDate->get($date, 0),
            ];
            $cursor->addDay();
        }

        $maximumActivity = max(array_column($activity, 'total'));

        foreach ($activity as &$activityDay) {
            $activityDay['percentage'] = $maximumActivity > 0
                ? round(($activityDay['total'] / $maximumActivity) * 100, 2)
                : 0;
        }
        unset($activityDay);

        return view('admin.dashboard', [
            'todayQuestions' => $todayQuestions,
            'activeKnowledgeCount' => $knowledgeStats['active'],
            'averageTop1Similarity' => $averageSimilarity === null
                ? null
                : (float) $averageSimilarity,
            'knowledgeStats' => $knowledgeStats,
            'activity' => $activity,
            'maximumActivity' => $maximumActivity,
        ]);
    }
}
