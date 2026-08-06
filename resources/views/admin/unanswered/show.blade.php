@extends('admin.layout')
@section('title', 'Tinjau Query')
@section('page-title', 'Tinjau Query')

@section('content')
    <div class="detail-grid">
        <section class="admin-card" aria-labelledby="query-detail-title">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Pertanyaan Pengguna</p><h2 id="query-detail-title">{{ $queryLog->query_text ?: 'Teks query tidak disimpan' }}</h2><p>Diterima {{ $queryLog->created_at->translatedFormat('d M Y H:i:s') }}.</p></div></div>
            <dl class="data-list">
                <div><dt>Normalisasi</dt><dd>{{ $queryLog->normalized_query ?: '—' }}</dd></div>
                <div><dt>Alasan unanswered</dt><dd>{{ str($queryLog->unanswered_reason ?: 'unknown')->replace('_', ' ')->title() }}</dd></div>
                <div><dt>Jumlah hasil</dt><dd>{{ number_format($queryLog->result_count, 0, ',', '.') }}</dd></div>
                <div><dt>Top-1 similarity</dt><dd>{{ $queryLog->top1_similarity === null ? '—' : number_format($queryLog->top1_similarity, 6, ',', '.') }}</dd></div>
                <div><dt>Status proses</dt><dd>{{ str($queryLog->status)->replace('_', ' ')->title() }}</dd></div>
            </dl>

            <h3 class="section-title">Intent terstruktur</h3>
            <pre class="code-block">{{ json_encode($queryLog->intent ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            <h3 class="section-title">Filter backend</h3>
            <pre class="code-block">{{ json_encode($queryLog->filters ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>

        <section class="admin-card" aria-labelledby="review-title">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Tindak Lanjut</p><h2 id="review-title">Status Peninjauan</h2><p>Catat perbaikan knowledge, alias, produk, atau listing yang dilakukan.</p></div></div>
            <form method="POST" action="{{ route('admin.unanswered.update', $queryLog) }}" data-admin-form>
                @csrf @method('PATCH')
                <input type="hidden" name="reviewed" value="0">
                <label class="check-field"><input name="reviewed" type="checkbox" value="1" @checked(old('reviewed', $queryLog->reviewed_at !== null))><span><strong>Sudah ditinjau</strong><small>Hilangkan centang untuk mengembalikan ke antrean.</small></span></label>
                <div class="form-field review-notes"><label for="review-notes">Catatan review</label><textarea id="review-notes" name="review_notes" maxlength="5000">{{ old('review_notes', $queryLog->review_notes) }}</textarea>@error('review_notes')<p class="form-error">{{ $message }}</p>@enderror</div>
                @if($queryLog->reviewed_at)<p class="form-help">Terakhir ditinjau {{ $queryLog->reviewed_at->translatedFormat('d M Y H:i') }}{{ $queryLog->reviewer ? ' oleh '.$queryLog->reviewer->name : '' }}.</p>@endif
                <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.unanswered.index') }}">Kembali</a><button class="button button--primary" type="submit" data-busy-label="Menyimpan…">Simpan Review</button></div>
            </form>
        </section>
    </div>
@endsection
