@extends('admin.layout')
@section('title', 'Edit Platform')
@section('page-title', 'Edit Platform')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ $platform->name }}</h2><p>Perbarui konektor dan ketersediaan sumber listing.</p></div></div>@include('admin.platforms._form', ['action' => route('admin.platforms.update', $platform), 'method' => 'PUT', 'submitLabel' => 'Simpan Perubahan'])</section>@endsection
