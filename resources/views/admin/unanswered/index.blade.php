@extends('admin.layout')
@section('title', 'Unanswered Queries')
@section('page-title', 'Unanswered Queries')

@section('content')
    <section class="admin-card" aria-labelledby="unanswered-title">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Evaluasi Chatbot</p><h2 id="unanswered-title">Pertanyaan yang Perlu Ditinjau</h2><p>Gunakan query ini untuk memperbaiki alias, produk, listing, atau knowledge tanpa menjadikan similarity sebagai jaminan relevansi.</p></div></div>
        <form class="filter-bar" method="GET" action="{{ route('admin.unanswered.index') }}">
            <div class="form-field filter-bar__search"><label for="unanswered-search">Cari query</label><input id="unanswered-search" name="search" type="search" value="{{ $search }}" placeholder="Teks pertanyaan pengguna"></div>
            <div class="form-field"><label for="unanswered-review">Peninjauan</label><select id="unanswered-review" name="review"><option value="all" @selected($review === 'all')>Semua</option><option value="pending" @selected($review === 'pending')>Belum ditinjau</option><option value="reviewed" @selected($review === 'reviewed')>Sudah ditinjau</option></select></div>
            <div class="form-field"><label for="unanswered-reason">Alasan</label><select id="unanswered-reason" name="reason"><option value="">Semua</option>@foreach($reasons as $value)<option value="{{ $value }}" @selected($reason === $value)>{{ str($value)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="filter-bar__actions"><button class="button button--secondary" type="submit">Terapkan</button><a class="button button--text" href="{{ route('admin.unanswered.index') }}">Reset</a></div>
        </form>

        @if($queries->isEmpty())<div class="empty-state"><p>Tidak ada unanswered query yang sesuai dengan filter.</p></div>@else
            <div class="table-scroll"><table class="admin-table"><thead><tr><th>Query</th><th>Alasan</th><th>Hasil</th><th>Top-1</th><th>Waktu</th><th>Review</th><th>Aksi</th></tr></thead><tbody>
                @foreach($queries as $query)
                    <tr>
                        <td class="admin-table__question"><strong>{{ $query->query_text ?: 'Teks query tidak disimpan' }}</strong><span>{{ $query->normalized_query ?: '—' }}</span></td>
                        <td><span class="badge badge--warning">{{ str($query->unanswered_reason ?: 'unknown')->replace('_', ' ')->title() }}</span></td>
                        <td>{{ number_format($query->result_count, 0, ',', '.') }}</td>
                        <td>{{ $query->top1_similarity === null ? '—' : number_format($query->top1_similarity, 6, ',', '.') }}</td>
                        <td>{{ $query->created_at->translatedFormat('d M Y H:i') }}</td>
                        <td><span class="badge badge--{{ $query->reviewed_at ? 'success' : 'warning' }}">{{ $query->reviewed_at ? 'Ditinjau' : 'Menunggu' }}</span></td>
                        <td><a class="button button--small button--secondary" href="{{ route('admin.unanswered.show', $query) }}">Tinjau</a></td>
                    </tr>
                @endforeach
            </tbody></table></div>
            <nav class="pagination" aria-label="Navigasi unanswered query"><span>{{ $queries->firstItem() }}–{{ $queries->lastItem() }} dari {{ $queries->total() }}</span><div class="pagination__links">@if($queries->onFirstPage())<span class="button button--small is-disabled">Sebelumnya</span>@else<a class="button button--small" href="{{ $queries->previousPageUrl() }}">Sebelumnya</a>@endif @if($queries->hasMorePages())<a class="button button--small" href="{{ $queries->nextPageUrl() }}">Berikutnya</a>@else<span class="button button--small is-disabled">Berikutnya</span>@endif</div></nav>
        @endif
    </section>
@endsection
