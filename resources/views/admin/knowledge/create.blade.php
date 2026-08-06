@extends('admin.layout')

@section('title', 'Tambah Knowledge')
@section('page-title', 'Tambah Knowledge')

@section('content')
    <section class="admin-card admin-card--form">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">Knowledge Base</p>
                <h2>Knowledge Baru</h2>
                <p>Embedding dibuat setelah knowledge berhasil disimpan.</p>
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
                'submitLabel' => 'Simpan Knowledge',
            ])
        </form>
    </section>
@endsection
