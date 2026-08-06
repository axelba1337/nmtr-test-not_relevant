@extends('admin.layout')
@section('title', 'Tambah Produk')
@section('page-title', 'Tambah Produk')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Katalog Kanonik</p><h2>Produk Baru</h2><p>Isi identitas dengan cukup rinci agar varian berbeda tidak tercampur.</p></div></div>@include('admin.products._form', ['action' => route('admin.products.store'), 'submitLabel' => 'Simpan Produk'])</section>@endsection
