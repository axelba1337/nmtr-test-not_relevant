@extends('admin.layout')
@section('title', 'Edit Produk')
@section('page-title', 'Edit Produk')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Katalog Kanonik</p><h2>{{ $product->name }}</h2><p>Perubahan identitas atau atribut akan membuat embedding lama berstatus perlu rebuild.</p></div></div>@include('admin.products._form', ['action' => route('admin.products.update', $product), 'method' => 'PUT', 'submitLabel' => 'Simpan Perubahan'])</section>@endsection
