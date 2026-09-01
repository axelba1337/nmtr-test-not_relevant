@extends('admin.layout')
@section('title', __('ui.admin_pages.platforms.edit'))
@section('page-title', __('ui.admin_pages.platforms.edit'))
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ $platform->name }}</h2><p>{{ __('ui.admin_pages.platforms.edit_help') }}</p></div></div>@include('admin.platforms._form', ['action' => route('admin.platforms.update', $platform), 'method' => 'PUT', 'submitLabel' => __('ui.common.save_changes')])</section>@endsection
