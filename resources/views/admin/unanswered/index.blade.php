@extends('admin.layout')
@section('title', __('ui.admin_pages.unanswered.index.title'))
@section('page-title', __('ui.admin_pages.unanswered.index.title'))

@section('content')
    <section class="admin-card" aria-labelledby="unanswered-title">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.unanswered.index.eyebrow') }}</p><h2 id="unanswered-title">{{ __('ui.admin_pages.unanswered.index.heading') }}</h2><p>{{ __('ui.admin_pages.unanswered.index.description') }}</p></div></div>
        <form class="filter-bar" method="GET" action="{{ route('admin.unanswered.index') }}">
            <div class="form-field filter-bar__search"><label for="unanswered-search">{{ __('ui.admin_pages.unanswered.index.search_label') }}</label><input id="unanswered-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.unanswered.index.search_placeholder') }}"></div>
            <div class="form-field"><label for="unanswered-review">{{ __('ui.admin_pages.unanswered.index.review_label') }}</label><select id="unanswered-review" name="review"><option value="all" @selected($review === 'all')>{{ __('ui.admin_pages.unanswered.common.all') }}</option><option value="pending" @selected($review === 'pending')>{{ __('ui.admin_pages.unanswered.index.not_reviewed') }}</option><option value="reviewed" @selected($review === 'reviewed')>{{ __('ui.admin_pages.unanswered.index.reviewed') }}</option></select></div>
            <div class="form-field"><label for="unanswered-reason">{{ __('ui.admin_pages.unanswered.index.reason_label') }}</label><select id="unanswered-reason" name="reason"><option value="">{{ __('ui.admin_pages.unanswered.common.all') }}</option>@foreach($reasons as $value)<option value="{{ $value }}" @selected($reason === $value)>{{ str($value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">{{ __('ui.admin_pages.unanswered.index.apply') }}</button><a class="button button--text" href="{{ route('admin.unanswered.index') }}">{{ __('ui.admin_pages.unanswered.index.reset') }}</a></div>
        </form>

        @if($queries->isEmpty())<div class="empty-state"><p>{{ __('ui.admin_pages.unanswered.index.empty') }}</p></div>@else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>{{ __('ui.admin_pages.unanswered.index.columns.query') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.reason') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.results') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.top_one') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.time') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.review') }}</th><th>{{ __('ui.admin_pages.unanswered.index.columns.actions') }}</th></tr></thead><tbody>
                @foreach($queries as $query)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $query->query_text ?: __('ui.admin_pages.unanswered.common.query_not_stored') }}</strong><span>{{ $query->normalized_query ?: __('ui.admin_pages.unanswered.common.not_available') }}</span></td>
                        <td><span class="badge badge--warning">{{ $query->unanswered_reason ? str($query->unanswered_reason)->replace('_', ' ')->title() : __('ui.admin_pages.unanswered.common.unknown') }}</span></td>
                        <td>{{ number_format($query->result_count, 0, ',', '.') }}</td>
                        <td>{{ $query->top1_similarity === null ? __('ui.admin_pages.unanswered.common.not_available') : number_format($query->top1_similarity, 6, ',', '.') }}</td>
                        <td>{{ $query->created_at->translatedFormat('d M Y H:i') }}</td>
                        <td><span class="badge badge--{{ $query->reviewed_at ? 'success' : 'warning' }}">{{ $query->reviewed_at ? __('ui.admin_pages.unanswered.index.reviewed_badge') : __('ui.admin_pages.unanswered.index.pending_badge') }}</span></td>
                        <td><a class="button button--small button--secondary" href="{{ route('admin.unanswered.show', $query) }}">{{ __('ui.admin_pages.unanswered.index.review_action') }}</a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="{{ __('ui.admin_pages.unanswered.index.pagination_label') }}"><span>{{ __('ui.admin_pages.unanswered.index.pagination_summary', ['first' => $queries->firstItem(), 'last' => $queries->lastItem(), 'total' => $queries->total()]) }}</span><div class="pagination__links">@if($queries->onFirstPage())<span class="button button--small is-disabled">{{ __('ui.admin_pages.unanswered.common.previous') }}</span>@else<a class="button button--small" href="{{ $queries->previousPageUrl() }}">{{ __('ui.admin_pages.unanswered.common.previous') }}</a>@endif @if($queries->hasMorePages())<a class="button button--small" href="{{ $queries->nextPageUrl() }}">{{ __('ui.admin_pages.unanswered.common.next') }}</a>@else<span class="button button--small is-disabled">{{ __('ui.admin_pages.unanswered.common.next') }}</span>@endif</div></nav>
        @endif
    </section>
@endsection
