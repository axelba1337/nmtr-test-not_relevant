@extends('admin.layout')
@section('title', 'Tambah Kategori')
@section('page-title', 'Tambah Kategori')
@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Katalog</p><h2>Kategori Baru</h2><p>Buat kategori utama atau subkategori produk.</p></div></div>
        @include('admin.categories._form', ['action' => route('admin.categories.store'), 'submitLabel' => 'Simpan Kategori'])
    </section>
@endsection
