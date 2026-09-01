@extends('admin.layout')

@section('title', __('ui.admin_pages.knowledge.index.title'))
@section('page-title', __('ui.admin_pages.knowledge.index.title'))

@section('content')
    <section class="admin-card">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.knowledge.common.eyebrow') }}</p>
                <h2>{{ __('ui.admin_pages.knowledge.index.heading') }}</h2>
                <p>{{ __('ui.admin_pages.knowledge.index.description') }}</p>
            </div>

            <div class="button-group">
                <form
                    method="POST"
                    action="{{ route('admin.knowledge.rebuild') }}"
                    data-confirm="{{ __('ui.admin_pages.knowledge.index.rebuild_all_confirmation') }}"
                >
                    @csrf
                    <button
                        class="button button--secondary"
                        type="submit"
                        data-disable-on-submit
                    >
                        {{ __('ui.admin_pages.knowledge.index.rebuild_all') }}
                    </button>
                </form>

                <a class="button button--primary" href="{{ route('admin.knowledge.create') }}">
                    {{ __('ui.admin_pages.knowledge.index.add') }}
                </a>
            </div>
        </div>

        <form class="filter-bar" method="GET" action="{{ route('admin.knowledge.index') }}">
            <div class="form-field filter-bar__search">
                <label for="search">{{ __('ui.admin_pages.knowledge.index.search_label') }}</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    value="{{ $search }}"
                    maxlength="255"
                    placeholder="{{ __('ui.admin_pages.knowledge.index.search_placeholder') }}"
                >
            </div>

            <div class="form-field">
                <label for="status">{{ __('ui.admin_pages.knowledge.index.active_status') }}</label>
                <select id="status" name="status">
                    <option value="all" @selected($status === 'all')>{{ __('ui.admin_pages.knowledge.common.all') }}</option>
                    <option value="active" @selected($status === 'active')>{{ __('ui.admin_pages.knowledge.common.active') }}</option>
                    <option value="inactive" @selected($status === 'inactive')>{{ __('ui.admin_pages.knowledge.common.inactive') }}</option>
                </select>
            </div>

            <div class="filter-bar__actions">
                <button class="button button--secondary" type="submit">{{ __('ui.admin_pages.knowledge.index.apply') }}</button>
                @if ($search !== '' || $status !== 'all')
                    <a class="button button--text" href="{{ route('admin.knowledge.index') }}">
                        {{ __('ui.admin_pages.knowledge.index.reset') }}
                    </a>
                @endif
            </div>
        </form>

        @if ($items->isEmpty())
            <div class="empty-state">
                <p>{{ __('ui.admin_pages.knowledge.index.empty') }}</p>
            </div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.knowledge') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.category_source') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.active_status') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.featured') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.embedding_status') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.updated_at') }}</th>
                            <th scope="col">{{ __('ui.admin_pages.knowledge.index.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php
                                $embeddingStatus = $item->embeddingStatus();
                                $embeddingMeta = match ($embeddingStatus) {
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY => [__('ui.admin_pages.knowledge.embedding.ready'), 'success'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY_WITH_WARNING => [__('ui.admin_pages.knowledge.embedding.ready_with_warning_short'), 'warning'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_FAILED => [__('ui.admin_pages.knowledge.embedding.failed_short'), 'danger'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_STALE => [__('ui.admin_pages.knowledge.embedding.stale_short'), 'warning'],
                                    default => [__('ui.admin_pages.knowledge.embedding.not_created_short'), 'neutral'],
                                };
                            @endphp
                            <tr>
                                <td class="admin-table__question">
                                    <strong>{{ $item->title }}</strong>
                                    <span>{{ \Illuminate\Support\Str::limit($item->content, 120) }}</span>
                                    <small>{{ __('ui.admin_pages.knowledge.index.sort_order', ['order' => number_format($item->sort_order, 0, ',', '.')]) }}</small>
                                </td>
                                <td>
                                    <strong>{{ $item->category?->name ?? __('ui.admin_pages.knowledge.common.no_category') }}</strong>
                                    <small class="table-meta">{{ $item->source_name ?: __('ui.admin_pages.knowledge.index.no_source') }}</small>
                                </td>
                                <td>
                                    <span class="badge badge--{{ $item->is_active ? 'success' : 'neutral' }}">
                                        {{ $item->is_active ? __('ui.admin_pages.knowledge.common.active') : __('ui.admin_pages.knowledge.common.inactive') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge--{{ $item->is_featured ? 'primary' : 'neutral' }}">
                                        {{ $item->is_featured ? __('ui.admin_pages.knowledge.common.yes') : __('ui.admin_pages.knowledge.common.no') }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge--{{ $embeddingMeta[1] }}">
                                        {{ $embeddingMeta[0] }}
                                    </span>
                                    @if ($item->embedded_at)
                                        <small class="table-meta">
                                            {{ $item->embedded_at->translatedFormat('d M Y H:i') }}
                                        </small>
                                    @endif
                                </td>
                                <td>{{ $item->updated_at->translatedFormat('d M Y H:i') }}</td>
                                <td>
                                    <div class="table-actions">
                                        <a
                                            class="button button--small button--secondary"
                                            href="{{ route('admin.knowledge.edit', $item) }}"
                                        >
                                            {{ __('ui.admin_pages.knowledge.index.actions.edit') }}
                                        </a>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.toggle-active', $item) }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button
                                                class="button button--small button--text"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                {{ $item->is_active ? __('ui.admin_pages.knowledge.index.actions.deactivate') : __('ui.admin_pages.knowledge.index.actions.activate') }}
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.toggle-featured', $item) }}"
                                        >
                                            @csrf
                                            @method('PATCH')
                                            <button
                                                class="button button--small button--text"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                {{ $item->is_featured ? __('ui.admin_pages.knowledge.index.actions.unfeature') : __('ui.admin_pages.knowledge.index.actions.feature') }}
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.embedding.rebuild', $item) }}"
                                            data-confirm="{{ __('ui.admin_pages.knowledge.index.rebuild_confirmation') }}"
                                        >
                                            @csrf
                                            <button
                                                class="button button--small button--text"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                {{ __('ui.admin_pages.knowledge.index.actions.rebuild') }}
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.destroy', $item) }}"
                                            data-confirm="{{ __('ui.admin_pages.knowledge.index.delete_confirmation') }}"
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="button button--small button--danger"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                {{ __('ui.admin_pages.knowledge.index.actions.delete') }}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($items->hasPages())
                <nav class="pagination" aria-label="{{ __('ui.admin_pages.knowledge.index.pagination_label') }}">
                    <div class="pagination__summary">
                        {{ __('ui.admin_pages.knowledge.index.pagination_summary', [
                            'first' => number_format($items->firstItem(), 0, ',', '.'),
                            'last' => number_format($items->lastItem(), 0, ',', '.'),
                            'total' => number_format($items->total(), 0, ',', '.'),
                        ]) }}
                    </div>
                    <div class="pagination__links">
                        @if ($items->onFirstPage())
                            <span class="button button--small button--secondary is-disabled">{{ __('ui.admin_pages.knowledge.common.previous') }}</span>
                        @else
                            <a class="button button--small button--secondary" href="{{ $items->previousPageUrl() }}">
                                {{ __('ui.admin_pages.knowledge.common.previous') }}
                            </a>
                        @endif

                        <span>{{ __('ui.admin_pages.knowledge.index.page_summary', ['current' => $items->currentPage(), 'last' => $items->lastPage()]) }}</span>

                        @if ($items->hasMorePages())
                            <a class="button button--small button--secondary" href="{{ $items->nextPageUrl() }}">
                                {{ __('ui.admin_pages.knowledge.common.next') }}
                            </a>
                        @else
                            <span class="button button--small button--secondary is-disabled">{{ __('ui.admin_pages.knowledge.common.next') }}</span>
                        @endif
                    </div>
                </nav>
            @endif
        @endif
    </section>
@endsection
