@extends('admin.layout')

@section('title', __('ui.admin.categories'))
@section('page-title', __('ui.admin.categories'))

@section('content')
    <section class="admin-card" aria-labelledby="categories-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin.catalog') }}</p>
                <h2 id="categories-title">{{ __('ui.admin_pages.categories.heading') }}</h2>
                <p>{{ __('ui.admin_pages.categories.description') }}</p>
            </div>
            <a class="button button--primary" href="{{ route('admin.categories.create') }}">{{ __('ui.admin_pages.categories.add') }}</a>
        </div>

        <form class="filter-bar" method="GET" action="{{ route('admin.categories.index') }}">
            <div class="form-field filter-bar__search">
                <label for="category-search">{{ __('ui.common.search') }}</label>
                <input id="category-search" name="search" type="search" value="{{ $search }}" placeholder="{{ __('ui.admin_pages.categories.search_placeholder') }}">
            </div>
            <div class="form-field">
                <label for="category-status">{{ __('ui.common.status') }}</label>
                <select id="category-status" name="status">
                    <option value="all" @selected($status === 'all')>{{ __('ui.common.all') }}</option>
                    <option value="active" @selected($status === 'active')>{{ __('ui.common.active') }}</option>
                    <option value="inactive" @selected($status === 'inactive')>{{ __('ui.common.inactive') }}</option>
                </select>
            </div>
            <x-admin-sort-select id="category-sort" :sort="$sort" />
            <div class="filter-bar__actions">
                <button class="button button--secondary" type="submit">{{ __('ui.common.apply') }}</button>
                <a class="button button--text" href="{{ route('admin.categories.index') }}">{{ __('ui.common.reset') }}</a>
            </div>
        </form>

        @if ($categories->isEmpty())
            <div class="empty-state"><p>{{ __('ui.admin_pages.categories.empty') }}</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>{{ __('ui.admin.categories') }}</th><th>{{ __('ui.admin_pages.categories.parent_short') }}</th><th>{{ __('ui.common.status') }}</th><th>{{ __('ui.admin_pages.common.sort_order') }}</th><th>{{ __('ui.admin_pages.common.relations') }}</th><th>{{ __('ui.common.actions') }}</th></tr></thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr>
                                <td class="admin-table__question">
                                    <strong>{{ $category->name }}</strong>
                                    <span>{{ $category->slug }}</span>
                                </td>
                                <td>{{ $category->parent?->name ?? '—' }}</td>
                                <td><span class="badge badge--{{ $category->is_active ? 'success' : 'neutral' }}">{{ $category->is_active ? __('ui.common.active') : __('ui.common.inactive') }}</span></td>
                                <td>{{ number_format($category->sort_order, 0, ',', '.') }}</td>
                                <td>
                                    <span class="table-meta">{{ trans_choice('ui.admin_pages.categories.subcategory_count', $category->children_count, ['count' => $category->children_count]) }}</span>
                                    <span class="table-meta">{{ trans_choice('ui.admin_pages.categories.product_count', $category->products_count, ['count' => $category->products_count]) }}</span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a class="button button--small button--secondary" href="{{ route('admin.categories.edit', $category) }}">{{ __('ui.common.edit') }}</a>
                                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" data-confirm="{{ __('ui.admin_pages.categories.delete_confirm') }}">
                                            @csrf @method('DELETE')
                                            <button class="button button--small button--danger" type="submit" data-busy-label="{{ __('ui.common.deleting') }}">{{ __('ui.common.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <nav class="pagination" aria-label="{{ __('ui.admin_pages.categories.pagination') }}">
                <span>{{ __('ui.admin_pages.common.pagination_summary', ['first' => $categories->firstItem(), 'last' => $categories->lastItem(), 'total' => $categories->total()]) }}</span>
                <div class="pagination__links">
                    @if ($categories->onFirstPage()) <span class="button button--small is-disabled">{{ __('ui.common.previous') }}</span> @else <a class="button button--small" href="{{ $categories->previousPageUrl() }}">{{ __('ui.common.previous') }}</a> @endif
                    @if ($categories->hasMorePages()) <a class="button button--small" href="{{ $categories->nextPageUrl() }}">{{ __('ui.common.next') }}</a> @else <span class="button button--small is-disabled">{{ __('ui.common.next') }}</span> @endif
                </div>
            </nav>
        @endif
    </section>
@endsection
