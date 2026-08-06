@extends('admin.layout')
@section('title', 'Edit Listing')
@section('page-title', 'Edit Listing')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>{{ $listing->title }}</h2><p>Perbarui pemetaan, harga, kondisi, dan biaya listing.</p></div></div>@include('admin.listings._form', ['action' => route('admin.listings.update', $listing), 'method' => 'PUT', 'submitLabel' => 'Simpan Perubahan'])</section>@endsection
