@extends('admin.layout')
@section('title', __('ui.admin.platforms'))
@section('page-title', __('ui.admin.platforms'))

@section('content')
    <section class="admin-card" aria-labelledby="platforms-title">
        <div class="admin-card__heading admin-card__heading--actions"><div><p class="admin-card__eyebrow">Marketplace</p><h2 id="platforms-title">{{ __('ui.admin_pages.platforms.heading') }}</h2><p>{{ __('ui.admin_pages.platforms.description') }}</p></div><a class="button button--primary" href="{{ route('admin.platforms.create') }}">{{ __('ui.admin_pages.platforms.add') }}</a></div>
        <form class="filter-bar" method="GET" action="{{ route('admin.platforms.index') }}">
            <div class="form-field filter-bar__search"><label for="platform-search">{{ __('ui.common.search') }}</label><input id="platform-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.platforms.search_placeholder') }}"></div>
            <div class="form-field"><label for="platform-source">{{ __('ui.admin_pages.platforms.source') }}</label><select id="platform-source" name="source_type"><option value="">{{ __('ui.common.all') }}</option>@foreach(\App\Models\Platform::sourceTypes() as $type)<option value="{{ $type }}" @selected($sourceType === $type)>{{ __('ui.admin_pages.platforms.values.'.$type) }}</option>@endforeach</select></div>
            <div class="form-field"><label for="platform-status-filter">{{ __('ui.common.status') }}</label><select id="platform-status-filter" name="status"><option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option><option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option><option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option><option value="syncable" @selected($status === 'syncable')>{{ __('ui.admin_pages.platforms.syncable') }}</option></select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">{{ __('ui.common.apply') }}</button><a class="button button--text" href="{{ route('admin.platforms.index') }}">{{ __('ui.common.reset') }}</a></div>
        </form>

        @if($platforms->isEmpty())<div class="empty-state"><p>{{ __('ui.admin_pages.platforms.empty') }}</p></div>@else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>Platform</th><th>{{ __('ui.admin_pages.platforms.access') }}</th><th>Connector</th><th>{{ __('ui.common.status') }}</th><th>Listing</th><th>{{ __('ui.admin_pages.platforms.sync') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead><tbody>
                @foreach($platforms as $platform)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $platform->name }}</strong><span>{{ $platform->base_url ?: $platform->slug }}</span></td>
                        <td><span class="badge badge--info">{{ __('ui.admin_pages.platforms.values.'.$platform->source_type) }}</span><span class="table-meta">{{ __('ui.admin_pages.platforms.values.'.$platform->access_type) }}</span></td>
                        <td><code>{{ $platform->connector ?: '—' }}</code></td>
                        <td><span class="badge badge--{{ $platform->is_active ? 'success' : 'neutral' }}">{{ $platform->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</span><span class="table-meta">{{ __('ui.admin_pages.platforms.connector_state', ['status' => __('ui.labels.'.$platform->status)]) }}</span></td>
                        <td>{{ number_format($platform->listings_count, 0, ',', '.') }}</td>
                        <td><span class="table-meta">{{ $platform->last_synced_at?->translatedFormat('d M Y H:i') ?? __('ui.admin_pages.platforms.never') }}</span>@if($platform->is_active && $platform->sync_enabled)<form method="POST" action="{{ route('admin.platforms.sync', $platform) }}" data-confirm="{{ __('ui.admin_pages.platforms.sync_confirm', ['platform' => $platform->name]) }}" class="table-action-form">@csrf<button class="button button--small button--text" type="submit" data-busy-label="{{ __('ui.admin_pages.platforms.syncing') }}">{{ __('ui.admin_pages.platforms.sync_now') }}</button></form>@else<span class="table-meta">{{ __('ui.admin_pages.platforms.sync_disabled') }}</span>@endif</td>
                        <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.platforms.edit', $platform) }}">{{ __('ui.common.edit') }}</a><form method="POST" action="{{ route('admin.platforms.destroy', $platform) }}" data-confirm="{{ __('ui.admin_pages.platforms.delete_confirm') }}">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="{{ __('ui.common.deleting') }}">{{ __('ui.common.delete') }}</button></form></div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.platforms.pagination') }}"><span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $platforms->firstItem(), 'last' => $platforms->lastItem(), 'total' => $platforms->total()]) }}</span><div class="pagination__links">@if($platforms->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span>@else<a class="button button--small" href="{{ $platforms->previousPageUrl() }}">{{ __('ui.common.previous') }}</a>@endif @if($platforms->hasMorePages())<a class="button button--small" href="{{ $platforms->nextPageUrl() }}">{{ __('ui.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.common.next') }}</span>@endif</div></nav>
        @endif
    </section>
@endsection
