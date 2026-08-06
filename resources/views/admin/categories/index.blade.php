@extends('admin.layout')

@section('title', 'Kategori')
@section('page-title', 'Kategori')

@section('content')
    <section class="admin-card" aria-labelledby="categories-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">Katalog</p>
                <h2 id="categories-title">Kategori dan Subkategori</h2>
                <p>Kelola struktur kategori yang dipakai produk dan knowledge.</p>
            </div>
            <a class="button button--primary" href="{{ route('admin.categories.create') }}">Tambah Kategori</a>
        </div>

        <form class="filter-bar" method="GET" action="{{ route('admin.categories.index') }}">
            <div class="form-field filter-bar__search">
                <label for="category-search">Cari</label>
                <input id="category-search" name="search" type="search" value="{{ $search }}" placeholder="Nama, slug, atau deskripsi">
            </div>
            <div class="form-field">
                <label for="category-status">Status</label>
                <select id="category-status" name="status">
                    <option value="all" @selected($status === 'all')>Semua</option>
                    <option value="active" @selected($status === 'active')>Aktif</option>
                    <option value="inactive" @selected($status === 'inactive')>Nonaktif</option>
                </select>
            </div>
            <div class="filter-bar__actions">
                <button class="button button--secondary" type="submit">Terapkan</button>
                <a class="button button--text" href="{{ route('admin.categories.index') }}">Reset</a>
            </div>
        </form>

        @if ($categories->isEmpty())
            <div class="empty-state"><p>Belum ada kategori yang sesuai dengan filter.</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>Kategori</th><th>Induk</th><th>Status</th><th>Urutan</th><th>Relasi</th><th>Aksi</th></tr></thead>
                    <tbody>
                        @foreach ($categories as $category)
                            <tr>
                                <td class="admin-table__question">
                                    <strong>{{ $category->name }}</strong>
                                    <span>{{ $category->slug }}</span>
                                </td>
                                <td>{{ $category->parent?->name ?? '—' }}</td>
                                <td><span class="badge badge--{{ $category->is_active ? 'success' : 'neutral' }}">{{ $category->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td>{{ number_format($category->sort_order, 0, ',', '.') }}</td>
                                <td>
                                    <span class="table-meta">{{ $category->children_count }} subkategori</span>
                                    <span class="table-meta">{{ $category->products_count }} produk</span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a class="button button--small button--secondary" href="{{ route('admin.categories.edit', $category) }}">Edit</a>
                                        <form method="POST" action="{{ route('admin.categories.destroy', $category) }}" data-confirm="Hapus kategori ini? Subkategori akan menjadi kategori utama.">
                                            @csrf @method('DELETE')
                                            <button class="button button--small button--danger" type="submit" data-busy-label="Menghapus…">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <nav class="pagination" aria-label="Navigasi kategori">
                <span>{{ $categories->firstItem() }}–{{ $categories->lastItem() }} dari {{ $categories->total() }}</span>
                <div class="pagination__links">
                    @if ($categories->onFirstPage()) <span class="button button--small is-disabled">Sebelumnya</span> @else <a class="button button--small" href="{{ $categories->previousPageUrl() }}">Sebelumnya</a> @endif
                    @if ($categories->hasMorePages()) <a class="button button--small" href="{{ $categories->nextPageUrl() }}">Berikutnya</a> @else <span class="button button--small is-disabled">Berikutnya</span> @endif
                </div>
            </nav>
        @endif
    </section>
@endsection
