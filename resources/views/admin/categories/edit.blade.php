@extends('admin.layout')
@section('title', 'Edit Kategori')
@section('page-title', 'Edit Kategori')
@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Katalog</p><h2>{{ $category->name }}</h2><p>Perbarui struktur dan status kategori.</p></div></div>
        @include('admin.categories._form', ['action' => route('admin.categories.update', $category), 'method' => 'PUT', 'submitLabel' => 'Simpan Perubahan'])
    </section>
@endsection
