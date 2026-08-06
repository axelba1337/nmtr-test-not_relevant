@extends('admin.layout')
@section('title', 'Listing')
@section('page-title', 'Listing')

@section('content')
    <section class="admin-card" aria-labelledby="listings-title">
        <div class="admin-card__heading admin-card__heading--actions"><div><p class="admin-card__eyebrow">Marketplace</p><h2 id="listings-title">Data Listing</h2><p>Listing hasil sinkronisasi dan data demo yang dipakai untuk perbandingan harga.</p></div><a class="button button--primary" href="{{ route('admin.listings.create') }}">Tambah Listing</a></div>
        <form class="filter-bar" method="GET" action="{{ route('admin.listings.index') }}">
            <div class="form-field filter-bar__search"><label for="listing-search">Cari</label><input id="listing-search" name="search" type="search" value="{{ $search }}" placeholder="Judul, seller, lokasi, atau ID eksternal"></div>
            <div class="form-field"><label for="listing-platform-filter">Platform</label><select id="listing-platform-filter" name="platform_id"><option value="">Semua</option>@foreach($platforms as $platform)<option value="{{ $platform->id }}" @selected((string) $platformId === (string) $platform->id)>{{ $platform->name }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-condition-filter">Kondisi</label><select id="listing-condition-filter" name="condition"><option value="">Semua</option>@foreach(\App\Models\Listing::conditions() as $value)<option value="{{ $value }}" @selected($condition === $value)>{{ str($value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-stock-filter">Stok</label><select id="listing-stock-filter" name="stock_status"><option value="">Semua</option>@foreach(\App\Models\Listing::stockStatuses() as $value)<option value="{{ $value }}" @selected($stockStatus === $value)>{{ str($value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="form-field"><label for="listing-status-filter">Status</label><select id="listing-status-filter" name="status"><option value="all" @selected($status === 'all')>Semua</option><option value="active" @selected($status === 'active')>Aktif</option><option value="inactive" @selected($status === 'inactive')>Nonaktif</option></select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">Terapkan</button><a class="button button--text" href="{{ route('admin.listings.index') }}">Reset</a></div>
        </form>

        @if($listings->isEmpty())<div class="empty-state"><p>Belum ada listing yang sesuai dengan filter.</p></div>@else
            <div class="table-scroll"><table class="admin-table admin-table--wide"><thead><tr><th>Listing</th><th>Platform / Produk</th><th>Harga</th><th>Kondisi</th><th>Seller</th><th>Stok</th><th>Diperbarui</th><th>Aksi</th></tr></thead><tbody>
                @foreach($listings as $listing)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $listing->title }}</strong><span>{{ $listing->external_listing_id ?: 'Tanpa ID eksternal' }}</span><a class="table-source-link" href="{{ $listing->product_url }}" target="_blank" rel="noopener noreferrer">Buka sumber</a></td>
                        <td>{{ $listing->platform?->name ?? '—' }}<span class="table-meta">{{ $listing->product?->name ?? 'Belum dipetakan' }}</span></td>
                        <td><strong>{{ $listing->currency === 'IDR' ? 'Rp' : $listing->currency.' ' }}{{ number_format($listing->price, 0, ',', '.') }}</strong><span class="table-meta">{{ $listing->currency }} · {{ str($listing->price_type)->replace('_', ' ')->title() }}</span></td>
                        <td><span class="badge badge--info">{{ str($listing->condition)->replace('_', ' ')->title() }}</span><span class="table-meta">Berdasarkan keterangan seller</span></td>
                        <td>{{ $listing->seller_name ?: '—' }}<span class="table-meta">{{ str($listing->seller_type)->replace('_', ' ')->title() }} · {{ $listing->location ?: 'Lokasi tidak diketahui' }}</span></td>
                        <td><span class="badge badge--{{ $listing->stock_status === 'in_stock' ? 'success' : ($listing->stock_status === 'out_of_stock' ? 'danger' : 'neutral') }}">{{ str($listing->stock_status)->replace('_', ' ')->title() }}</span><span class="table-meta">{{ $listing->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td>{{ $listing->last_synced_at?->translatedFormat('d M Y H:i') ?? $listing->updated_at->translatedFormat('d M Y H:i') }}</td>
                        <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.listings.edit', $listing) }}">Edit</a><form method="POST" action="{{ route('admin.listings.destroy', $listing) }}" data-confirm="Hapus listing ini?">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="Menghapus…">Hapus</button></form></div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="Navigasi listing"><span>{{ $listings->firstItem() }}–{{ $listings->lastItem() }} dari {{ $listings->total() }}</span><div class="pagination__links">@if($listings->onFirstPage())<span class="button button--small is-disabled">Sebelumnya</span>@else<a class="button button--small" href="{{ $listings->previousPageUrl() }}">Sebelumnya</a>@endif @if($listings->hasMorePages())<a class="button button--small" href="{{ $listings->nextPageUrl() }}">Berikutnya</a>@else<span class="button button--small is-disabled">Berikutnya</span>@endif</div></nav>
        @endif
    </section>
@endsection
