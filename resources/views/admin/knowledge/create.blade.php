@extends('admin.layout')

@section('title', __('ui.admin_pages.knowledge.create.title'))
@section('page-title', __('ui.admin_pages.knowledge.create.title'))

@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">{{ __('ui.admin_pages.knowledge.common.eyebrow') }}</p>
                <h2>{{ __('ui.admin_pages.knowledge.create.heading') }}</h2>
                <p>{{ __('ui.admin_pages.knowledge.create.description') }}</p>
            </div>
        </div>

        <form
            class="admin-form"
            method="POST"
            action="{{ route('admin.knowledge.store') }}"
        >
            @csrf
            @include('admin.knowledge._form', [
                'item' => null,
                'submitLabel' => __('ui.admin_pages.knowledge.create.submit'),
            ])
        </form>
    </section>
@endsection
