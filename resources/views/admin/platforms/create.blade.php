@extends('admin.layout')
@section('title', 'Tambah Platform')
@section('page-title', 'Tambah Platform')
@section('content')<section class="admin-card admin-card--form"><div class="admin-card__heading"><div><p class="admin-card__eyebrow">Marketplace</p><h2>Platform Baru</h2><p>Secret provider tetap disimpan melalui environment, bukan form ini.</p></div></div>@include('admin.platforms._form', ['action' => route('admin.platforms.store'), 'submitLabel' => 'Simpan Platform'])</section>@endsection
