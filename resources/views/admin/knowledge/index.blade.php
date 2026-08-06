@extends('admin.layout')

@section('title', 'Knowledge Base')
@section('page-title', 'Knowledge Base')

@section('content')
    <section class="admin-card">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">Knowledge Base</p>
                <h2>Daftar Knowledge</h2>
                <p>Kelola knowledge, sumber, contoh pertanyaan, dan kesiapan embedding.</p>
            </div>

            <div class="button-group">
                <form
                    method="POST"
                    action="{{ route('admin.knowledge.rebuild') }}"
                    data-confirm="Buat ulang embedding seluruh knowledge aktif? Proses ini dapat memerlukan waktu."
                >
                    @csrf
                    <button
                        class="button button--secondary"
                        type="submit"
                        data-disable-on-submit
                    >
                        Rebuild Knowledge
                    </button>
                </form>

                <a class="button button--primary" href="{{ route('admin.knowledge.create') }}">
                    Tambah Knowledge
                </a>
            </div>
        </div>

        <form class="filter-bar" method="GET" action="{{ route('admin.knowledge.index') }}">
            <div class="form-field filter-bar__search">
                <label for="search">Cari judul, isi, kata kunci, atau sumber</label>
                <input
                    id="search"
                    name="search"
                    type="search"
                    value="{{ $search }}"
                    maxlength="255"
                    placeholder="Ketik kata kunci"
                >
            </div>

            <div class="form-field">
                <label for="status">Status aktif</label>
                <select id="status" name="status">
                    <option value="all" @selected($status === 'all')>Semua</option>
                    <option value="active" @selected($status === 'active')>Aktif</option>
                    <option value="inactive" @selected($status === 'inactive')>Nonaktif</option>
                </select>
            </div>

            <div class="filter-bar__actions">
                <button class="button button--secondary" type="submit">Terapkan</button>
                @if ($search !== '' || $status !== 'all')
                    <a class="button button--text" href="{{ route('admin.knowledge.index') }}">
                        Reset
                    </a>
                @endif
            </div>
        </form>

        @if ($items->isEmpty())
            <div class="empty-state">
                <p>Belum ada knowledge yang sesuai dengan filter.</p>
            </div>
        @else
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th scope="col">Knowledge</th>
                            <th scope="col">Kategori / Sumber</th>
                            <th scope="col">Status aktif</th>
                            <th scope="col">Featured</th>
                            <th scope="col">Status embedding</th>
                            <th scope="col">Terakhir diperbarui</th>
                            <th scope="col">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $item)
                            @php
                                $embeddingStatus = $item->embeddingStatus();
                                $embeddingMeta = match ($embeddingStatus) {
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY => ['Siap', 'success'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY_WITH_WARNING => ['Siap, rebuild gagal', 'warning'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_FAILED => ['Gagal', 'danger'],
                                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_STALE => ['Kedaluwarsa', 'warning'],
                                    default => ['Belum dibuat', 'neutral'],
                                };
                            @endphp
                            <tr>
                                <td class="admin-table__question">
                                    <strong>{{ $item->title }}</strong>
                                    <span>{{ \Illuminate\Support\Str::limit($item->content, 120) }}</span>
                                    <small>Urutan: {{ number_format($item->sort_order, 0, ',', '.') }}</small>
                                </td>
                                <td>
                                    <strong>{{ $item->category?->name ?? 'Tanpa kategori' }}</strong>
                                    <small class="table-meta">{{ $item->source_name ?: 'Sumber tidak dicantumkan' }}</small>
                                </td>
                                <td>
                                    <span class="badge badge--{{ $item->is_active ? 'success' : 'neutral' }}">
                                        {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge--{{ $item->is_featured ? 'primary' : 'neutral' }}">
                                        {{ $item->is_featured ? 'Ya' : 'Tidak' }}
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
                                            Edit
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
                                                {{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
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
                                                {{ $item->is_featured ? 'Lepas featured' : 'Jadikan featured' }}
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.embedding.rebuild', $item) }}"
                                            data-confirm="Buat ulang embedding knowledge ini?"
                                        >
                                            @csrf
                                            <button
                                                class="button button--small button--text"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                Rebuild
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('admin.knowledge.destroy', $item) }}"
                                            data-confirm="Hapus knowledge ini? Tindakan ini tidak dapat dibatalkan."
                                        >
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="button button--small button--danger"
                                                type="submit"
                                                data-disable-on-submit
                                            >
                                                Hapus
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
                <nav class="pagination" aria-label="Navigasi halaman knowledge">
                    <div class="pagination__summary">
                        Menampilkan {{ number_format($items->firstItem(), 0, ',', '.') }}
                        –{{ number_format($items->lastItem(), 0, ',', '.') }}
                        dari {{ number_format($items->total(), 0, ',', '.') }} knowledge
                    </div>
                    <div class="pagination__links">
                        @if ($items->onFirstPage())
                            <span class="button button--small button--secondary is-disabled">Sebelumnya</span>
                        @else
                            <a class="button button--small button--secondary" href="{{ $items->previousPageUrl() }}">
                                Sebelumnya
                            </a>
                        @endif

                        <span>Halaman {{ $items->currentPage() }} dari {{ $items->lastPage() }}</span>

                        @if ($items->hasMorePages())
                            <a class="button button--small button--secondary" href="{{ $items->nextPageUrl() }}">
                                Berikutnya
                            </a>
                        @else
                            <span class="button button--small button--secondary is-disabled">Berikutnya</span>
                        @endif
                    </div>
                </nav>
            @endif
        @endif
    </section>
@endsection
