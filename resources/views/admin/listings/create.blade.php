@extends('admin.layout')
@section('title', 'Tambah Listing')
@section('page-title', 'Tambah Listing')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>Listing Baru</h2><p>Tambahkan listing demo atau manual dengan sumber yang dapat diverifikasi.</p></div></div>@include('admin.listings._form', ['action' => route('admin.listings.store'), 'submitLabel' => 'Simpan Listing'])</section>@endsection
