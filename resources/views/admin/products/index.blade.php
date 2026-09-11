@extends('admin.layout')
@section('title', __('ui.admin.products_aliases'))
@section('page-title', __('ui.admin.products_aliases'))

@section('content')
    <section class="admin-card" aria-labelledby="products-title">
        <div class="admin-card__heading admin-card__heading--actions products-index__heading">
            <div class="products-index__heading-copy">
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.products.catalog') }}</p>
                <h2 id="products-title">{{ __('ui.admin_pages.products.heading') }}</h2>
                <p>{{ __('ui.admin_pages.products.description') }}</p>
            </div>
            <div class="button-group products-index__header-actions">
                <a class="button button--primary" href="{{ route('admin.products.create') }}">{{ __('ui.admin_pages.products.add') }}</a>
            </div>
        </div>

        <form class="filter-bar products-filter" method="GET" action="{{ route('admin.products.index') }}">
            <div class="products-filter__row products-filter__row--primary">
                <div class="form-field products-filter__search"><label for="product-search">{{ __('ui.common.search') }}</label><input id="product-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.products.search_placeholder') }}"></div>
                <div class="form-field"><label for="product-category">{{ __('ui.admin.categories') }}</label><select id="product-category" name="category_id"><option value="">{{ __('ui.admin_pages.products.all_categories') }}</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
                <div class="form-field"><label for="product-status">{{ __('ui.common.status') }}</label><select id="product-status" name="status"><option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option><option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option><option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option></select></div>
            </div>
            <div class="products-filter__row products-filter__row--secondary">
                <div class="form-field"><label for="product-listing-status">{{ __('ui.admin_pages.products.listing_status') }}</label><select id="product-listing-status" name="listing_status"><option value="all" @selected($listingStatus === 'all')>{{ __('ui.common.all') }}</option><option value="without_active" @selected($listingStatus === 'without_active')>{{ __('ui.admin_pages.products.without_active_listings') }}</option></select></div>
                <div class="form-field"><label for="product-embedding-status">{{ __('ui.admin_pages.products.embedding_filter') }}</label><select id="product-embedding-status" name="embedding_status">@foreach(\App\Services\Embedding\ProductEmbeddingStatus::values() as $value)<option value="{{ $value }}" @selected($embeddingStatus === $value)>{{ $value === 'all' ? __('ui.common.all') : __('ui.admin_pages.products.embedding_states.'.$value) }}</option>@endforeach</select></div>
                <x-admin-sort-select id="product-sort" :sort="$sort" :options="$sortOptions" />
                <div class="filter-bar__actions products-filter__actions"><button class="button button--secondary" type="submit">{{ __('ui.common.apply') }}</button><a class="button button--text" href="{{ route('admin.products.index') }}">{{ __('ui.common.reset') }}</a></div>
            </div>
            <p class="form-help products-filter__help"><em>{{ __('ui.admin_pages.products.embedding_failure_help') }}</em></p>
        </form>

        @if($products->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.products.empty') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>{{ __('ui.admin_pages.products.product') }}</th><th>{{ __('ui.admin.categories') }}</th><th>{{ __('ui.admin_pages.products.variant_sku') }}</th><th>{{ __('ui.admin_pages.products.spec_source') }}</th><th>{{ __('ui.common.status') }}</th><th>Embedding</th><th>{{ __('ui.admin_pages.common.relations') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach($products as $product)
                            @php
                                $productEmbeddingStatus = $product->embeddingStatus(
                                    (string) config('ai.embedding.model'),
                                    (int) config('ai.embedding.dimensions'),
                                );
                            @endphp
                            <tr>
                                <td class="admin-table__question"><strong>{{ $product->name }}</strong><span>{{ $product->brand ?: __('ui.admin_pages.products.brand_missing') }}{{ $product->model ? ' · '.$product->model : '' }}</span></td>
                                <td>{{ $product->category?->name ?? '—' }}</td>
                                <td>{{ $product->variant ?: '—' }}<span class="table-meta">SKU: {{ $product->sku_or_part_number ?: '—' }}</span></td>
                                <td>@if($product->spec_source_url)<a href="{{ $product->spec_source_url }}" target="_blank" rel="noopener noreferrer">{{ $product->spec_source_name ?: __('ui.admin_pages.products.official_source') }}</a>@else{{ $product->spec_source_name ?: '—' }}@endif<span class="table-meta">{{ $product->spec_source_checked_at?->format('d M Y') ?? __('ui.admin_pages.products.not_verified') }}</span></td>
                                <td><span class="badge badge--{{ $product->is_active ? 'success' : 'neutral' }}">{{ $product->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</span></td>
                                <td><span class="badge badge--{{ $productEmbeddingStatus === 'ready' ? 'success' : ($productEmbeddingStatus === 'failed' ? 'danger' : 'warning') }}" @if($productEmbeddingStatus === 'failed') title="{{ str($product->embedding_error)->limit(240) }}" @endif>{{ __('ui.admin_pages.products.embedding_states.'.$productEmbeddingStatus) }}</span><span class="table-meta">{{ $product->embedding_model ?: __('ui.admin_pages.products.no_model') }}</span></td>
                                <td><span class="table-meta">{{ trans_choice('ui.admin_pages.products.alias_count', $product->aliases_count, ['count' => $product->aliases_count]) }}</span><span class="table-meta">{{ trans_choice('ui.admin_pages.products.listing_count', $product->listings_count, ['count' => $product->listings_count]) }}</span></td>
                                <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.products.edit', $product) }}">{{ __('ui.common.edit') }}</a><form method="POST" action="{{ route('admin.products.embedding.rebuild', $product) }}">@csrf<button class="button button--small button--text" type="submit" data-busy-label="{{ __('ui.common.processing') }}">{{ __('ui.admin_pages.products.rebuild_embedding') }}</button></form><form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm="{{ __('ui.admin_pages.products.delete_confirm') }}">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="{{ __('ui.common.deleting') }}">{{ __('ui.common.delete') }}</button></form></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.products.pagination') }}"><span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $products->firstItem(), 'last' => $products->lastItem(), 'total' => $products->total()]) }}</span><div class="pagination__links">@if($products->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>@else<a class="button button--small" href="{{ $products->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>@endif @if($products->hasMorePages())<a class="button button--small" href="{{ $products->nextPageUrl() }}">{{ __('ui.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>@endif</div></nav>
        @endif
    </section>
@endsection
