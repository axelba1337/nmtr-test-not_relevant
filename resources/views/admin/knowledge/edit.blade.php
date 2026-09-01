@extends('admin.layout')

@section('title', __('ui.admin_pages.knowledge.edit.title'))
@section('page-title', __('ui.admin_pages.knowledge.edit.title'))

@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading admin-card__heading--actions">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.knowledge.common.eyebrow') }}</p>
                <h2>{{ __('ui.admin_pages.knowledge.edit.heading') }}</h2>
                <p>
                    {{ __('ui.admin_pages.knowledge.edit.description') }}
                </p>
            </div>

            @php
                $embeddingStatus = $item->embeddingStatus();
                $embeddingLabel = match ($embeddingStatus) {
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY => __('ui.admin_pages.knowledge.embedding.ready_full'),
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_READY_WITH_WARNING => __('ui.admin_pages.knowledge.embedding.ready_with_warning_full'),
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_FAILED => __('ui.admin_pages.knowledge.embedding.failed_full'),
                    \App\Models\KnowledgeItem::EMBEDDING_STATUS_STALE => __('ui.admin_pages.knowledge.embedding.stale_full'),
                    default => __('ui.admin_pages.knowledge.embedding.not_created_full'),
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
                'submitLabel' => __('ui.admin_pages.knowledge.edit.submit'),
            ])
        </form>
    </section>
@endsection
