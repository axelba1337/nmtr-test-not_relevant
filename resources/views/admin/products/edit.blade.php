@extends('admin.layout')
@section('title', __('ui.admin_pages.products.edit'))
@section('page-title', __('ui.admin_pages.products.edit'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.products.catalog') }}</p><h2>{{ $product->name }}</h2><p>{{ __('ui.admin_pages.products.edit_help') }}</p></div></div>@include('admin.products._form', ['action' => route('admin.products.update', $product), 'method' => 'PUT', 'submitLabel' => __('ui.common.save_changes')])</section>@endsection
