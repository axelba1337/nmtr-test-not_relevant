@extends('admin.layout')

@section('title', __('ui.admin.dashboard'))
@section('page-title', __('ui.admin.dashboard'))

@section('content')
    <div class="summary-grid summary-grid--dashboard" aria-label="{{ __('ui.admin.dashboard_page.eyebrow') }}">
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
            <p class="summary-card__label">{{ __('ui.admin.dashboard_page.knowledge_status') }}</p>
            <p class="summary-card__value">{{ number_format($activeProductsCount, 0, ',', '.') }}</p>
            <p class="summary-card__note">{{ __('ui.admin.dashboard_page.products_aliases_semantic') }}</p>
            <ul class="summary-card__details">
                <li><a href="{{ route('admin.knowledge.index') }}">{{ __('ui.admin.dashboard_page.active_knowledge_count', ['count' => number_format($activeKnowledgeCount, 0, ',', '.')]) }}</a></li>
            </ul>
            <p class="summary-card__note">{{ __('ui.admin.dashboard_page.active_knowledge_explanation') }}</p>
        </article>
    </div>

    <section class="admin-card scope-summary" aria-labelledby="mvp-scope-title">
        <div>
            <p class="admin-card__eyebrow">{{ __('ui.admin.dashboard_page.scope_eyebrow') }}</p>
            <h2 id="mvp-scope-title">{{ __('ui.admin.dashboard_page.scope_title') }}</h2>
            <p class="scope-summary__description">{{ __('ui.admin.dashboard_page.category_scope_description') }}</p>
        </div>
        <p><strong>{{ __('ui.admin.dashboard_page.five_domains') }}</strong> {{ __('ui.admin.dashboard_page.domain_list') }}</p>
        <p><strong>{{ __('ui.admin.dashboard_page.five_sources') }}</strong> {{ __('ui.admin.dashboard_page.source_list') }}</p>
    </section>

    <section class="admin-card" aria-labelledby="products-without-listings-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin.dashboard_page.operational_warning') }}</p>
                <h2 id="products-without-listings-title">{{ __('ui.admin.dashboard_page.products_without_listings') }}</h2>
                <p>{{ __('ui.admin.dashboard_page.products_without_listings_description', ['count' => number_format($productsWithoutListingsCount, 0, ',', '.')]) }}</p>
            </div>
            <a class="button button--secondary" href="{{ route('admin.products.index', ['status' => 'active', 'listing_status' => 'without_active']) }}">{{ __('ui.admin.dashboard_page.review_products') }}</a>
        </div>

        @if ($productsWithoutListings->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin.dashboard_page.all_products_have_listings') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>{{ __('ui.admin_pages.products.product') }}</th><th>{{ __('ui.admin.categories') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.admin.dashboard_page.latest_listing_sync') }}</th></tr></thead>
                    <tbody>
                        @foreach ($productsWithoutListings as $product)
                            <tr>
                                <td class="admin-table__question"><strong>{{ $product->name }}</strong><span>{{ $product->brand ?: __('ui.admin_pages.products.brand_missing') }}</span></td>
                                <td>{{ $product->category?->name ?? '—' }}</td>
                                <td><span class="badge badge--success">{{ __('ui.common.active') }}</span></td>
                                <td>{{ trans_choice('ui.admin_pages.products.listing_count', $product->listings_count, ['count' => $product->listings_count]) }}<span class="table-meta">{{ $product->listings_max_last_synced_at ?: __('ui.admin.dashboard_page.never_synced') }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

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
