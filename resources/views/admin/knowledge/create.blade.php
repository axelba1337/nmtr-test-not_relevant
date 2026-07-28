@extends('admin.layout')

@section('title', 'Tambah FAQ')
@section('page-title', 'Tambah FAQ')

@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">FAQ / Knowledge</p>
                <h2>FAQ Baru</h2>
                <p>Embedding dibuat setelah FAQ berhasil disimpan.</p>
            </div>
        </div>

        <form
            class="admin-form"
            method="POST"
            action="{{ route('admin.knowledge.store') }}"
        >
            @csrf
            @include('admin.knowledge._form', [
                'item' => null,
                'submitLabel' => 'Simpan FAQ',
            ])
        </form>
    </section>
@endsection
