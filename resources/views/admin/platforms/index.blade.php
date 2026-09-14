@extends('admin.layout')
@section('title', __('ui.admin.platforms'))
@section('page-title', __('ui.admin.platforms'))

@section('content')
    <section class="admin-card" aria-labelledby="platforms-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.platforms.source_registry') }}</p>
                <h2 id="platforms-title">{{ __('ui.admin_pages.platforms.heading') }}</h2>
                <p>{{ __('ui.admin_pages.platforms.description') }}</p>
            </div>
            <div class="header-actions">
                <a class="button button--secondary" href="{{ route('admin.platforms.export', $exportQuery) }}">{{ __('ui.admin_pages.common.download_xlsx') }}</a>
            </div>
        </div>

        @if($unsupportedPlatforms->isNotEmpty())
            <div class="alert alert--warning" role="status">
                <p>{{ __('ui.admin_pages.platforms.unsupported_detected', ['platforms' => $unsupportedPlatforms->implode(', ')]) }}</p>
            </div>
        @endif

        <form class="filter-bar" method="GET" action="{{ route('admin.platforms.index') }}">
            <div class="form-field filter-bar__search"><label for="platform-search">{{ __('ui.common.search') }}</label><input id="platform-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.platforms.search_placeholder') }}"></div>
            <div class="form-field"><label for="platform-status-filter">{{ __('ui.common.status') }}</label><select id="platform-status-filter" name="status"><option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option><option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option><option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option></select></div>
            <x-admin-sort-select id="platform-sort" :sort="$sort" />
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">{{ __('ui.common.apply') }}</button><a class="button button--text" href="{{ route('admin.platforms.index') }}">{{ __('ui.common.reset') }}</a></div>
        </form>

        @if($platforms->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.platforms.empty') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>Platform</th><th>{{ __('ui.admin_pages.platforms.active_listings') }}</th><th>{{ __('ui.admin_pages.platforms.last_update') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach($platforms as $platform)
                            <tr>
                                <td class="admin-table__question"><strong>{{ $platform->name }}</strong></td>
                                <td>{{ number_format($platform->active_listings_count, 0, ',', '.') }}</td>
                                <td><span class="table-meta">{{ filled($platform->latest_listing_activity_at) ? \Illuminate\Support\Carbon::parse($platform->latest_listing_activity_at)->translatedFormat('d M Y H:i') : '—' }}</span></td>
                                <td><span class="badge badge--{{ $platform->is_active ? 'success' : 'neutral' }}">{{ $platform->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</span></td>
                                <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.platforms.edit', $platform) }}">{{ __('ui.common.edit') }}</a></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.platforms.pagination') }}"><span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $platforms->firstItem(), 'last' => $platforms->lastItem(), 'total' => $platforms->total()]) }}</span><div class="pagination__links">@if($platforms->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>@else<a class="button button--small" href="{{ $platforms->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>@endif @if($platforms->hasMorePages())<a class="button button--small" href="{{ $platforms->nextPageUrl() }}">{{ __('ui.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>@endif</div></nav>
        @endif
    </section>
@endsection
