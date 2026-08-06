@extends('admin.layout')

@section('title', 'Edit Knowledge')
@section('page-title', 'Edit Knowledge')

@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">Knowledge Base</p>
                <h2>Edit Knowledge</h2>
                <p>
                    Embedding dibuat ulang jika judul, kategori, kata kunci, isi, atau nama sumber berubah.
                </p>
            </div>

            @php
                $embeddingStatus = $item->embeddingStatus();
                $embeddingLabel = match ($embeddingStatus) {
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY => 'Embedding siap',
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY_WITH_WARNING => 'Embedding siap dengan peringatan',
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_FAILED => 'Embedding gagal',
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_STALE => 'Embedding kedaluwarsa',
                    default => 'Embedding belum dibuat',
                };
            @endphp
            <span class="badge badge--neutral">{{ $embeddingLabel }}</span>
        </div>

        <form
            class="admin-form"
            method="POST"
            action="{{ route('admin.knowledge.update', $item) }}"
        >
            @csrf
            @method('PUT')
            @include('admin.knowledge._form', [
                'item' => $item,
                'submitLabel' => 'Simpan Perubahan',
            ])
        </form>
    </section>
@endsection
