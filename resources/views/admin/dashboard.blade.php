@extends('admin.layout')

@section('title', __('ui.admin.dashboard'))
@section('page-title', __('ui.admin.dashboard'))

@section('content')
    <div class="summary-grid" aria-label="{{ __('ui.admin.dashboard_page.eyebrow') }}">
        <article class="summary-card">
            <p class="summary-card__label">{{ __('ui.admin.dashboard_page.questions_today') }}</p>
            <p class="summary-card__value">{{ number_format($todayQuestions, 0, ',', '.') }}</p>
            <p class="summary-card__note">{{ __('ui.admin.dashboard_page.description') }}</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">{{ __('ui.admin.listings') }} {{ __('ui.common.active') }}</p>
            <p class="summary-card__value">{{ number_format($activeListingsCount, 0, ',', '.') }}</p>
            <p class="summary-card__note">{{ __('ui.chat.js.ranking_note') }}</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">{{ __('ui.admin.dashboard_page.top_similarity') }}</p>
            <p class="summary-card__value">
                {{ $averageTop1Similarity === null ? '—' : number_format($averageTop1Similarity, 4, ',', '.') }}
            </p>
            <p class="summary-card__note">{{ __('ui.admin.dashboard_page.monitoring_only') }}</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">{{ __('ui.admin.dashboard_page.knowledge_status') }}</p>
            <p class="summary-card__value">
                {{ number_format($activeProductsCount, 0, ',', '.') }}
                <span class="summary-card__unit">{{ mb_strtolower(__('ui.admin.products_aliases')) }}</span>
            </p>
            <ul class="summary-card__details">
                <li>{{ __('ui.admin.dashboard_page.active_knowledge_count', ['count' => number_format($activeKnowledgeCount, 0, ',', '.')]) }}</li>
                <li>{{ __('ui.admin.dashboard_page.ready_embedding_count', ['count' => number_format($knowledgeStats['ready'], 0, ',', '.')]) }}</li>
                <li>{{ __('ui.admin.dashboard_page.attention_count', ['count' => number_format($knowledgeStats['failed'] + $knowledgeStats['pending'], 0, ',', '.')]) }}</li>
                <li>{{ __('ui.admin.dashboard_page.pending_query_count', ['count' => number_format($pendingUnansweredCount, 0, ',', '.')]) }}</li>
                @if ($knowledgeStats['warnings'] > 0)
                    <li>{{ __('ui.admin.dashboard_page.warning_count', ['count' => number_format($knowledgeStats['warnings'], 0, ',', '.')]) }}</li>
                @endif
            </ul>
        </article>
    </div>

    <section class="admin-card" aria-labelledby="activity-title">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin.dashboard_page.eyebrow') }}</p>
                <h2 id="activity-title">{{ __('ui.admin.dashboard_page.activity') }}</h2>
            </div>
        </div>

        @if ($maximumActivity === 0)
            <div class="empty-state">
                <p>{{ __('ui.admin.dashboard_page.empty') }}</p>
            </div>
        @else
            <div
                class="activity-chart"
                role="img"
                aria-label="{{ __('ui.admin.dashboard_page.chart_label') }}"
            >
                <div class="activity-chart__body">
                    <div class="activity-chart__y-axis" aria-hidden="true">
                        <span>{{ number_format($maximumActivity, 0, ',', '.') }}</span>
                        <span>{{ number_format((int) ceil($maximumActivity / 2), 0, ',', '.') }}</span>
                        <span>0</span>
                    </div>

                    <div class="activity-chart__plot">
                        @foreach ($activity as $activityDay)
                            <div class="activity-chart__column">
                                <span class="activity-chart__total">
                                    {{ number_format($activityDay['total'], 0, ',', '.') }}
                                </span>
                                <span
                                    class="activity-chart__bar"
                                    style="--activity-height: {{ $activityDay['percentage'] }}%"
                                    aria-hidden="true"
                                ></span>
                                <time datetime="{{ $activityDay['date'] }}">
                                    {{ $activityDay['label'] }}
                                </time>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection
