@extends('admin.layout')
@section('title', __('ui.admin_pages.categories.edit'))
@section('page-title', __('ui.admin_pages.categories.edit'))
@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin.catalog') }}</p><h2>{{ $category->name }}</h2><p>{{ __('ui.admin_pages.categories.edit_help') }}</p></div></div>
        @include('admin.categories._form', ['action' => route('admin.categories.update', $category), 'method' => 'PUT', 'submitLabel' => __('ui.common.save_changes')])
    </section>
@endsection
