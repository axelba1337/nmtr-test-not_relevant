@extends('admin.layout')
@section('title', __('ui.admin_pages.platforms.add'))
@section('page-title', __('ui.admin_pages.platforms.add'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ __('ui.admin_pages.platforms.new') }}</h2><p>{{ __('ui.admin_pages.platforms.create_help') }}</p></div></div>@include('admin.platforms._form', ['action' => route('admin.platforms.store'), 'submitLabel' => __('ui.admin_pages.platforms.save')])</section>@endsection
