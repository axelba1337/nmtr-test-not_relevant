@extends('admin.layout')
@section('title', $mappingStatus === 'unmatched' ? __('ui.admin_pages.listings.unmatched_heading') : __('ui.admin.listings'))
@section('page-title', $mappingStatus === 'unmatched' ? __('ui.admin_pages.listings.unmatched_heading') : __('ui.admin.listings'))

@section('content')
    <section class="admin-card" aria-labelledby="listings-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.listings.source_integration') }}</p><h2 id="listings-title">{{ __('ui.admin_pages.listings.heading') }}</h2><p>{{ __('ui.admin_pages.listings.description') }}</p></div>
            <a class="button button--primary" href="{{ route('admin.listings.create') }}">{{ __('ui.admin_pages.listings.add') }}</a>
        </div>
        <form class="filter-bar" method="GET" action="{{ route('admin.listings.index') }}">
            <div class="form-field filter-bar__search"><label for="listing-search">{{ __('ui.common.search') }}</label><input id="listing-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.listings.search_placeholder') }}"></div>
            <div class="form-field"><label for="listing-platform-filter">Platform</label><select id="listing-platform-filter" name="platform_id"><option value="">{{ __('ui.common.all') }}</option>@foreach($platforms as $platform)<option value="{{ $platform->id }}" @selected((string) $platformId === (string) $platform->id)>{{ $platform->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-condition-filter">{{ __('ui.admin_pages.listings.condition') }}</label><select id="listing-condition-filter" name="condition"><option value="">{{ __('ui.common.all') }}</option>@foreach(\App\Models\Listing::conditions() as $value)<option value="{{ $value }}" @selected($condition === $value)>{{ __('ui.labels.'.$value) }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-stock-filter">{{ __('ui.admin_pages.listings.stock') }}</label><select id="listing-stock-filter" name="stock_status"><option value="">{{ __('ui.common.all') }}</option>@foreach(\App\Models\Listing::stockStatuses() as $value)<option value="{{ $value }}" @selected($stockStatus === $value)>{{ __('ui.labels.'.$value) }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-status-filter">{{ __('ui.common.status') }}</label><select id="listing-status-filter" name="status"><option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option><option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option><option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option></select></div>
            <div class="form-field"><label for="listing-mapping-filter">{{ __('ui.admin_pages.listings.mapping_status') }}</label><select id="listing-mapping-filter" name="mapping_status"><option value="all" @selected($mappingStatus === 'all')>{{ __('ui.common.all') }}</option><option value="matched" @selected($mappingStatus === 'matched')>{{ __('ui.admin_pages.listings.matched') }}</option><option value="unmatched" @selected($mappingStatus === 'unmatched')>{{ __('ui.admin_pages.listings.unmatched') }}</option></select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">{{ __('ui.common.apply') }}</button><a class="button button--text" href="{{ route('admin.listings.index') }}">{{ __('ui.common.reset') }}</a></div>
        </form>

        @if($listings->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.listings.empty') }}</p></div>
        @else
            <div class="table-scroll"><table class="admin-table admin-table--wide"><thead><tr><th>{{ __('ui.admin.listings') }}</th><th>{{ __('ui.admin_pages.listings.platform_product') }}</th><th>{{ __('ui.admin_pages.listings.price') }}</th><th>{{ __('ui.admin_pages.listings.condition') }}</th><th>{{ __('ui.admin_pages.listings.seller') }}</th><th>{{ __('ui.admin_pages.listings.stock') }}</th><th>{{ __('ui.common.updated') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead><tbody>
                @foreach($listings as $listing)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $listing->title }}</strong><span>{{ $listing->external_listing_id ?: __('ui.admin_pages.listings.no_external_id') }}</span><a class="table-source-link" href="{{ $listing->product_url }}" target="_blank" rel="noopener noreferrer">{{ __('ui.common.open_source') }}</a></td>
                        <td>{{ $listing->platform?->name ?? '—' }}<span class="table-meta">{{ $listing->product?->name ?? __('ui.common.unmapped') }}</span></td>
                        <td><strong>{{ $listing->currency === 'IDR' ? 'Rp' : $listing->currency.' ' }}{{ number_format($listing->price, 0, ',', '.') }}</strong><span class="table-meta">{{ $listing->currency }} · {{ __('ui.labels.'.$listing->price_type) }}</span></td>
                        <td><span class="badge badge--info">{{ __('ui.labels.'.$listing->condition) }}</span><span class="table-meta">{{ __('ui.messages.search.condition_seller') }}</span></td>
                        <td>{{ $listing->seller_name ?: '—' }}<span class="table-meta">{{ $listing->location ?: __('ui.admin_pages.listings.unknown_location') }}</span></td>
                        <td><span class="badge badge--{{ $listing->stock_status === 'in_stock' ? 'success' : ($listing->stock_status === 'out_of_stock' ? 'danger' : 'neutral') }}">{{ __('ui.labels.'.$listing->stock_status) }}</span><span class="table-meta">{{ $listing->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</span></td>
                        <td>{{ $listing->last_synced_at?->translatedFormat('d M Y H:i') ?? $listing->updated_at->translatedFormat('d M Y H:i') }}</td>
                        <td><div class="table-actions">@if($listing->product_id === null)<a class="button button--small button--primary" href="{{ route('admin.listings.candidate.review', $listing) }}">{{ __('ui.admin_pages.listings.review_candidate') }}</a>@endif<a class="button button--small button--secondary" href="{{ route('admin.listings.edit', $listing) }}">{{ __('ui.common.edit') }}</a><form method="POST" action="{{ route('admin.listings.destroy', $listing) }}" data-confirm="{{ __('ui.admin_pages.listings.delete_confirm') }}">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="{{ __('ui.common.deleting') }}">{{ __('ui.common.delete') }}</button></form></div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.listings.pagination') }}"><span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $listings->firstItem(), 'last' => $listings->lastItem(), 'total' => $listings->total()]) }}</span><div class="pagination__links">@if($listings->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>@else<a class="button button--small" href="{{ $listings->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>@endif @if($listings->hasMorePages())<a class="button button--small" href="{{ $listings->nextPageUrl() }}">{{ __('ui.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>@endif</div></nav>
        @endif
    </section>
@endsection
