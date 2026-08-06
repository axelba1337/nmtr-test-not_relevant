@extends('admin.layout')
@section('title', 'Produk & Alias')
@section('page-title', 'Produk & Alias')

@section('content')
    <section class="admin-card" aria-labelledby="products-title">
        <div class="admin-card__heading admin-card__heading--actions">
            <div><p class="admin-card__eyebrow">Katalog Kanonik</p><h2 id="products-title">Produk dan Alias</h2><p>Identitas terstruktur mencegah varian produk yang berbeda dianggap sama.</p></div>
            <a class="button button--primary" href="{{ route('admin.products.create') }}">Tambah Produk</a>
        </div>

        <form class="filter-bar" method="GET" action="{{ route('admin.products.index') }}">
            <div class="form-field filter-bar__search"><label for="product-search">Cari</label><input id="product-search" name="search" type="search" value="{{ $search }}" placeholder="Nama, merek, model, varian, atau SKU"></div>
            <div class="form-field"><label for="product-category">Kategori</label><select id="product-category" name="category_id"><option value="">Semua kategori</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="product-status">Status</label><select id="product-status" name="status"><option value="all" @selected($status === 'all')>Semua</option><option value="active" @selected($status === 'active')>Aktif</option><option value="inactive" @selected($status === 'inactive')>Nonaktif</option></select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">Terapkan</button><a class="button button--text" href="{{ route('admin.products.index') }}">Reset</a></div>
        </form>

        @if($products->isEmpty())
            <div class="empty-state"><p>Belum ada produk yang sesuai dengan filter.</p></div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead><tr><th>Produk</th><th>Kategori</th><th>Varian / SKU</th><th>Status</th><th>Embedding</th><th>Relasi</th><th>Aksi</th></tr></thead>
                    <tbody>
                        @foreach($products as $product)
                            @php
                                $embeddingReady = $product->hasCurrentEmbedding(
                                    (string) config('ai.embedding.model'),
                                    (int) config('ai.embedding.dimensions'),
                                );
                            @endphp
                            <tr>
                                <td class="admin-table__question"><strong>{{ $product->name }}</strong><span>{{ $product->brand ?: 'Merek belum diisi' }}{{ $product->model ? ' · '.$product->model : '' }}</span></td>
                                <td>{{ $product->category?->name ?? '—' }}</td>
                                <td>{{ $product->variant ?: '—' }}<span class="table-meta">SKU: {{ $product->sku_or_part_number ?: '—' }}</span></td>
                                <td><span class="badge badge--{{ $product->is_active ? 'success' : 'neutral' }}">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                                <td><span class="badge badge--{{ $embeddingReady ? 'success' : ($product->embedding_error ? 'danger' : 'warning') }}">{{ $embeddingReady ? 'Siap' : ($product->embedding_error ? 'Gagal' : 'Perlu rebuild') }}</span><span class="table-meta">{{ $product->embedding_model ?: 'Model belum ada' }}</span></td>
                                <td><span class="table-meta">{{ $product->aliases_count }} alias</span><span class="table-meta">{{ $product->listings_count }} listing</span></td>
                                <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.products.edit', $product) }}">Edit</a><form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm="Hapus produk kanonik ini? Alias akan dihapus dan listing dipertahankan tanpa relasi produk.">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="Menghapus…">Hapus</button></form></div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <nav class="pagination" aria-label="Navigasi produk"><span>{{ $products->firstItem() }}–{{ $products->lastItem() }} dari {{ $products->total() }}</span><div class="pagination__links">@if($products->onFirstPage())<span class="button button--small is-disabled">Sebelumnya</span>@else<a class="button button--small" href="{{ $products->previousPageUrl() }}">Sebelumnya</a>@endif @if($products->hasMorePages())<a class="button button--small" href="{{ $products->nextPageUrl() }}">Berikutnya</a>@else<span class="button button--small is-disabled">Berikutnya</span>@endif</div></nav>
        @endif
    </section>
@endsection
