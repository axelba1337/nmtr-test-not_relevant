@extends('admin.layout')
@section('title', __('ui.admin_pages.categories.add'))
@section('page-title', __('ui.admin_pages.categories.add'))
@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin.catalog') }}</p><h2>{{ __('ui.admin_pages.categories.new') }}</h2><p>{{ __('ui.admin_pages.categories.create_help') }}</p></div></div>
        @include('admin.categories._form', ['action' => route('admin.categories.store'), 'submitLabel' => __('ui.admin_pages.categories.save')])
    </section>
@endsection
