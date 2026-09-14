@extends('admin.layout')
@section('title', __('ui.admin_pages.listing_coverage.title'))
@section('page-title', __('ui.admin_pages.listing_coverage.title'))

@section('content')
    <section class="admin-card" aria-labelledby="listing-coverage-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.listing_coverage.eyebrow') }}</p><h2 id="listing-coverage-title">{{ __('ui.admin_pages.listing_coverage.heading') }}</h2><p>{{ __('ui.admin_pages.listing_coverage.description', ['target' => $target]) }}</p></div>
            <div class="header-actions">
                <a class="button button--text" href="{{ route('admin.listings.import') }}">{{ __('ui.admin_pages.listing_coverage.back_to_import') }}</a>
                <a class="button button--secondary" href="{{ route('admin.listings.coverage.research-prompt') }}">{{ __('ui.admin_pages.listing_coverage.download_prompt') }}</a>
                <a class="button button--primary" href="{{ route('admin.listings.coverage.export') }}">{{ __('ui.admin_pages.listing_coverage.export') }}</a>
            </div>
        </div>
        <div class="alert alert--info"><p>{{ __('ui.admin_pages.listing_import.workflow_help') }}</p></div>
        <div class="summary-grid summary-grid--compact">
            @foreach(['active', 'matched', 'unmatched', 'without_active', 'needing'] as $metric)
                <article class="summary-card"><p class="summary-card__label">{{ __('ui.admin_pages.listing_coverage.metrics.'.$metric) }}</p><p class="summary-card__value">{{ number_format($metrics[$metric], 0, ',', '.') }}</p></article>
            @endforeach
        </div>

        @if($products->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.listing_coverage.empty') }}</p></div>
        @else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>{{ __('ui.admin_pages.listing_coverage.product') }}</th><th>{{ __('ui.admin_pages.listing_coverage.category') }}</th><th>{{ __('ui.admin_pages.listing_coverage.identity') }}</th><th>{{ __('ui.admin_pages.listing_coverage.current') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead><tbody>
                @foreach($products as $product)
                    <tr><td class="admin-table__question"><strong>{{ $product->name }}</strong><span>#{{ $product->id }}</span></td><td>{{ $product->category?->name ?? '—' }}</td><td>{{ collect([$product->brand, $product->model, $product->variant])->filter()->implode(' · ') ?: '—' }}</td><td>{{ number_format($product->active_listings_count, 0, ',', '.') }}</td><td><a class="button button--small button--secondary" href="{{ route('admin.products.edit', $product) }}">{{ __('ui.common.view') }}</a></td></tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.listing_coverage.pagination') }}">
                <span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $products->firstItem(), 'last' => $products->lastItem(), 'total' => $products->total()]) }}</span>
                <div class="pagination__links">
                    @if($products->onFirstPage())
                        <span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>
                    @else
                        <a class="button button--small" href="{{ $products->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>
                    @endif
                    @if($products->hasMorePages())
                        <a class="button button--small" href="{{ $products->nextPageUrl() }}">{{ __('ui.common.next') }}</a>
                    @else
                        <span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>
                    @endif
                </div>
            </nav>
        @endif
    </section>
@endsection
