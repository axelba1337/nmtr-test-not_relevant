@extends('admin.layout')
@section('title', __('ui.admin_pages.products.add'))
@section('page-title', __('ui.admin_pages.products.add'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.products.catalog') }}</p><h2>{{ __('ui.admin_pages.products.new') }}</h2><p>{{ __('ui.admin_pages.products.create_help') }}</p></div></div>@include('admin.products._form', ['action' => route('admin.products.store'), 'submitLabel' => __('ui.admin_pages.products.save')])</section>@endsection
