@extends('admin.layout')
@section('title', __('ui.admin_pages.listing_discovery.run_detail_title', ['id' => $run->id]))
@section('page-title', __('ui.admin_pages.listing_discovery.run_detail_title', ['id' => $run->id]))

@section('content')
    <section class="admin-card">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_discovery.runs') }}</p>
                <h2>{{ __('ui.admin_pages.listing_discovery.run_detail_heading', ['id' => $run->id]) }}</h2>
                <p>{{ __('ui.admin_pages.listing_discovery.run_detail_help') }}</p>
            </div>
            <a class="button" href="{{ route('admin.listings.discovery') }}">{{ __('ui.admin_pages.listing_discovery.back_to_discovery') }}</a>
        </div>
        @if($hasHistoricalUnresolvedProducts)
            <div class="alert alert--warning"><p>{{ __('ui.admin_pages.listing_discovery.historical_unresolved_help') }}</p></div>
        @endif
        <dl class="data-list">
            <div><dt>{{ __('ui.common.status') }}</dt><dd>{{ strtoupper($run->status) }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listing_discovery.products_checked') }}</dt><dd>{{ $run->products_checked }} / {{ $run->products_targeted }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listing_discovery.candidates_found') }}</dt><dd>{{ $run->candidates_found }}</dd></div>
            <div><dt>{{ __('ui.admin_pages.listing_discovery.valid_rejected') }}</dt><dd>{{ $run->valid_candidates }} / {{ $run->rejected_candidates }}</dd></div>
        </dl>
    </section>

    <section class="admin-card" aria-labelledby="run-products-title">
        <div class="admin-card__heading">
            <div>
                <h2 id="run-products-title">{{ __('ui.admin_pages.listing_discovery.checked_products') }}</h2>
                <p>{{ __('ui.admin_pages.listing_discovery.review_status_help') }}</p>
            </div>
        </div>

        <form class="filter-bar discovery-run-filter" method="GET" action="{{ route('admin.listings.discovery.runs.show', $run) }}">
            <div class="form-field discovery-run-filter__search">
                <label for="run-product-search">{{ __('ui.admin_pages.listing_discovery.search_product') }}</label>
                <input id="run-product-search" type="search" name="search" value="{{ $filters['search'] }}" placeholder="{{ __('ui.admin_pages.listing_discovery.search_product_placeholder') }}">
            </div>
            <div class="form-field">
                <label for="run-result-status">{{ __('ui.admin_pages.listing_discovery.result_filter') }}</label>
                <select id="run-result-status" name="result_status">
                    <option value="">{{ __('ui.common.all') }}</option>
                    @foreach($resultStatuses as $status)
                        <option value="{{ $status }}" @selected($filters['result_status'] === $status)>{{ strtoupper(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="run-review-status">{{ __('ui.admin_pages.listing_discovery.review_filter') }}</label>
                <select id="run-review-status" name="review_status">
                    <option value="">{{ __('ui.common.all') }}</option>
                    <option value="pending" @selected($filters['review_status'] === 'pending')>{{ __('ui.admin_pages.listing_discovery.review_pending') }}</option>
                    <option value="approved" @selected($filters['review_status'] === 'approved')>{{ __('ui.admin_pages.listing_discovery.review_approved') }}</option>
                    <option value="rejected" @selected($filters['review_status'] === 'rejected')>{{ __('ui.admin_pages.listing_discovery.review_rejected') }}</option>
                    <option value="no_candidates" @selected($filters['review_status'] === 'no_candidates')>{{ __('ui.admin_pages.listing_discovery.no_candidates_short') }}</option>
                </select>
            </div>
            <div class="form-field">
                <label for="run-category">{{ __('ui.admin_pages.listing_coverage.category') }}</label>
                <select id="run-category" name="category">
                    <option value="">{{ __('ui.common.all') }}</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="run-product-sort">{{ __('ui.admin_pages.listing_discovery.sort') }}</label>
                <select id="run-product-sort" name="sort">
                    <option value="newest" @selected($filters['sort'] === 'newest')>{{ __('ui.admin_pages.listing_discovery.sort_newest_checked') }}</option>
                    <option value="oldest" @selected($filters['sort'] === 'oldest')>{{ __('ui.admin_pages.listing_discovery.sort_oldest_checked') }}</option>
                    <option value="product_az" @selected($filters['sort'] === 'product_az')>{{ __('ui.admin_pages.listing_discovery.sort_product_az') }}</option>
                    <option value="product_za" @selected($filters['sort'] === 'product_za')>{{ __('ui.admin_pages.listing_discovery.sort_product_za') }}</option>
                </select>
            </div>
            <div class="filter-bar__actions">
                <button class="button button--primary" type="submit">{{ __('ui.common.apply') }}</button>
                <a class="button" href="{{ route('admin.listings.discovery.runs.show', $run) }}">{{ __('ui.common.reset') }}</a>
            </div>
        </form>

        @if($runProducts->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.listing_discovery.no_matching_run_products') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table admin-table--wide">
                    <thead><tr><th>{{ __('ui.admin_pages.listing_coverage.product') }}</th><th>{{ __('ui.admin_pages.listing_coverage.category') }}</th><th>{{ __('ui.admin_pages.listing_discovery.available_before') }}</th><th>{{ __('ui.admin_pages.listing_coverage.still_needed') }}</th><th>{{ __('ui.admin_pages.listing_discovery.pipeline_diagnostics') }}</th><th>{{ __('ui.admin_pages.listing_discovery.candidates_found') }}</th><th>{{ __('ui.admin_pages.listing_discovery.valid_rejected') }}</th><th>{{ __('ui.admin_pages.listing_discovery.provider_result') }}</th><th>{{ __('ui.admin_pages.listing_discovery.review_status') }}</th><th>{{ __('ui.admin_pages.listing_discovery.checked_at') }}</th><th>{{ __('ui.admin_pages.listing_discovery.current_available') }}</th></tr></thead>
                    <tbody>
                        @foreach($runProducts as $runProduct)
                            @php
                                $reviews = $candidateReviews->get($runProduct->product_id, ['pending' => 0, 'approved' => 0, 'rejected' => 0]);
                                $diagnostics = is_array($runProduct->diagnostics) ? $runProduct->diagnostics : [];
                                $rejectionReasons = is_array($diagnostics['rejection_reasons'] ?? null) ? $diagnostics['rejection_reasons'] : [];
                            @endphp
                            <tr>
                                <td><strong>{{ $runProduct->product?->name ?? '—' }}</strong><span class="table-meta">{{ collect([$runProduct->product?->brand, $runProduct->product?->model])->filter()->implode(' · ') }}</span></td>
                                <td>{{ $runProduct->product?->category?->name ?? '—' }}</td>
                                <td>{{ $runProduct->available_listings_before }} / {{ $coverageTarget }}</td>
                                <td>{{ $runProduct->still_needed }}</td>
                                <td>
                                    @if($diagnostics === [])
                                        <span class="table-meta">{{ __('ui.common.unavailable') }}</span>
                                    @else
                                        <span class="discovery-diagnostic-summary">{{ __('ui.admin_pages.listing_discovery.diagnostic_summary', ['search' => (int) ($diagnostics['search_results'] ?? 0), 'direct' => (int) ($diagnostics['direct_listing_urls'] ?? 0), 'matched' => (int) ($diagnostics['product_matches'] ?? 0), 'valid' => (int) ($diagnostics['valid_candidates'] ?? 0)]) }}</span>
                                        <details class="discovery-diagnostics">
                                            <summary>{{ __('ui.admin_pages.listing_discovery.view_diagnostics') }}</summary>
                                            <dl>
                                                @foreach([
                                                    'search_results',
                                                    'supported_domain_results',
                                                    'direct_listing_urls',
                                                    'product_matches',
                                                    'search_evidence_candidates',
                                                    'http_probe_attempts',
                                                    'http_probe_success',
                                                    'extract_attempts',
                                                    'extract_success',
                                                    'valid_candidates',
                                                    'rejected_candidates',
                                                ] as $metric)
                                                    <div><dt>{{ __('ui.admin_pages.listing_discovery.diagnostics.'.$metric) }}</dt><dd>{{ (int) ($diagnostics[$metric] ?? 0) }}</dd></div>
                                                @endforeach
                                            </dl>
                                            @if($rejectionReasons !== [])
                                                <p class="table-meta">{{ __('ui.admin_pages.listing_discovery.rejection_reasons') }}</p>
                                                <ul>
                                                    @foreach($rejectionReasons as $reason => $count)
                                                        <li>{{ str_replace('_', ' ', (string) $reason) }}: {{ (int) $count }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </details>
                                    @endif
                                </td>
                                <td>{{ $runProduct->candidates_found }}</td>
                                <td>{{ $runProduct->valid_candidates }} / {{ $runProduct->rejected_candidates }}</td>
                                <td><span class="badge badge--{{ $runProduct->status === 'failed' ? 'danger' : ($runProduct->status === 'candidate_found' ? 'success' : ($runProduct->status === 'partial' ? 'warning' : 'neutral')) }}">{{ strtoupper(str_replace('_', ' ', $runProduct->status)) }}</span>@if($runProduct->provider_error_code)<span class="table-meta">{{ $runProduct->provider_error_code }}</span>@endif</td>
                                <td>
                                    @if($runProduct->candidates_found === 0)
                                        <span class="badge badge--neutral">{{ __('ui.admin_pages.listing_discovery.no_candidates_short') }}</span>
                                    @else
                                        {{ __('ui.admin_pages.listing_discovery.review_counts', $reviews) }}
                                    @endif
                                </td>
                                <td>{{ $runProduct->finished_at?->translatedFormat('d M Y H:i:s') ?? $runProduct->started_at?->translatedFormat('d M Y H:i:s') ?? '—' }}@if($runProduct->started_at && $runProduct->finished_at)<span class="table-meta">{{ __('ui.admin_pages.listing_discovery.started_finished', ['started' => $runProduct->started_at->translatedFormat('H:i:s'), 'finished' => $runProduct->finished_at->translatedFormat('H:i:s')]) }}</span>@endif</td>
                                <td>{{ (int) ($runProduct->product?->available_listings_count ?? 0) }} / {{ $coverageTarget }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($runProducts->hasPages())
                <nav class="pagination" aria-label="{{ __('ui.admin_pages.listing_discovery.pagination') }}">
                    <span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $runProducts->firstItem(), 'last' => $runProducts->lastItem(), 'total' => $runProducts->total()]) }}</span>
                    <div class="pagination__links">
                        @if($runProducts->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>@else<a class="button button--small" href="{{ $runProducts->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>@endif
                        @if($runProducts->hasMorePages())<a class="button button--small" href="{{ $runProducts->nextPageUrl() }}">{{ __('ui.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>@endif
                    </div>
                </nav>
            @endif
        @endif
    </section>
@endsection
