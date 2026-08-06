@extends('admin.layout')
@section('title', 'Platform')
@section('page-title', 'Platform')

@section('content')
    <section class="admin-card" aria-labelledby="platforms-title">
        <div class="admin-card__heading admin-card__heading--actions"><div><p class="admin-card__eyebrow">Marketplace</p><h2 id="platforms-title">Sumber Listing</h2><p>Kelola adapter API, feed, dataset demo, dan input manual tanpa menyimpan secret di database.</p></div><a class="button button--primary" href="{{ route('admin.platforms.create') }}">Tambah Platform</a></div>
        <form class="filter-bar" method="GET" action="{{ route('admin.platforms.index') }}">
            <div class="form-field filter-bar__search"><label for="platform-search">Cari</label><input id="platform-search" name="search" type="search" value="{{ $search }}" placeholder="Nama, slug, atau connector"></div>
            <div class="form-field"><label for="platform-source">Sumber</label><select id="platform-source" name="source_type"><option value="">Semua</option>@foreach(\App\Models\Platform::sourceTypes() as $type)<option value="{{ $type }}" @selected($sourceType === $type)>{{ str($type)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="form-field"><label for="platform-status-filter">Status</label><select id="platform-status-filter" name="status"><option value="all" @selected($status === 'all')>Semua</option><option value="active" @selected($status === 'active')>Aktif</option><option value="inactive" @selected($status === 'inactive')>Nonaktif</option><option value="syncable" @selected($status === 'syncable')>Dapat disinkronkan</option></select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">Terapkan</button><a class="button button--text" href="{{ route('admin.platforms.index') }}">Reset</a></div>
        </form>

        @if($platforms->isEmpty())<div class="empty-state"><p>Belum ada platform yang sesuai dengan filter.</p></div>@else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>Platform</th><th>Akses</th><th>Connector</th><th>Status</th><th>Listing</th><th>Sinkronisasi</th><th>Aksi</th></tr></thead><tbody>
                @foreach($platforms as $platform)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $platform->name }}</strong><span>{{ $platform->base_url ?: $platform->slug }}</span></td>
                        <td><span class="badge badge--info">{{ str($platform->source_type)->replace('_', ' ')->title() }}</span><span class="table-meta">{{ str($platform->access_type)->replace('_', ' ')->title() }}</span></td>
                        <td><code>{{ $platform->connector ?: '—' }}</code></td>
                        <td><span class="badge badge--{{ $platform->is_active ? 'success' : 'neutral' }}">{{ $platform->is_active ? 'Aktif' : 'Nonaktif' }}</span><span class="table-meta">Connector: {{ str($platform->status)->replace('_', ' ')->title() }}</span></td>
                        <td>{{ number_format($platform->listings_count, 0, ',', '.') }}</td>
                        <td><span class="table-meta">{{ $platform->last_synced_at?->translatedFormat('d M Y H:i') ?? 'Belum pernah' }}</span>@if($platform->is_active && $platform->sync_enabled)<form method="POST" action="{{ route('admin.platforms.sync', $platform) }}" data-confirm="Sinkronkan listing platform {{ $platform->name }} sekarang?" class="table-action-form">@csrf<button class="button button--small button--text" type="submit" data-busy-label="Menyinkronkan…">Sync sekarang</button></form>@else<span class="table-meta">Sync nonaktif</span>@endif</td>
                        <td><div class="table-actions"><a class="button button--small button--secondary" href="{{ route('admin.platforms.edit', $platform) }}">Edit</a><form method="POST" action="{{ route('admin.platforms.destroy', $platform) }}" data-confirm="Hapus platform ini? Platform dengan listing tidak dapat dihapus.">@csrf @method('DELETE')<button class="button button--small button--danger" type="submit" data-busy-label="Menghapus…">Hapus</button></form></div></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="Navigasi platform"><span>{{ $platforms->firstItem() }}–{{ $platforms->lastItem() }} dari {{ $platforms->total() }}</span><div class="pagination__links">@if($platforms->onFirstPage())<span class="button button--small is-disabled">Sebelumnya</span>@else<a class="button button--small" href="{{ $platforms->previousPageUrl() }}">Sebelumnya</a>@endif @if($platforms->hasMorePages())<a class="button button--small" href="{{ $platforms->nextPageUrl() }}">Berikutnya</a>@else<span class="button button--small is-disabled">Berikutnya</span>@endif</div></nav>
        @endif
    </section>
@endsection
