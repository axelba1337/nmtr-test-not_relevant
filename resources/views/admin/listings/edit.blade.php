@extends('admin.layout')
@section('title', __('ui.admin_pages.listings.edit'))
@section('page-title', __('ui.admin_pages.listings.edit'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ $listing->title }}</h2><p>{{ __('ui.admin_pages.listings.edit_help') }}</p></div></div>@include('admin.listings._form', ['action' => route('admin.listings.update', $listing), 'method' => 'PUT', 'submitLabel' => __('ui.common.save_changes')])</section>@endsection
