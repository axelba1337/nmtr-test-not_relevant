@extends('admin.layout')
@section('title', __('ui.admin_pages.listings.add'))
@section('page-title', __('ui.admin_pages.listings.add'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ __('ui.admin_pages.listings.new') }}</h2><p>{{ __('ui.admin_pages.listings.create_help') }}</p></div></div>@include('admin.listings._form', ['action' => route('admin.listings.store'), 'submitLabel' => __('ui.admin_pages.listings.save')])</section>@endsection
