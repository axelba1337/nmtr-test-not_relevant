@extends('admin.layout')
@section('title', 'Bobot Ranking')
@section('page-title', 'Bobot Ranking')

@section('content')
    <section class="admin-card admin-card--form" aria-labelledby="ranking-title">
        <div class="admin-card__heading"><div><p class="admin-card__eyebrow">Perhitungan Deterministik</p><h2 id="ranking-title">Bobot Peringkat Hasil</h2><p>Total bobot wajib 1,00. Chat API hanya menjelaskan hasil dan tidak mengubah skor backend.</p></div></div>
        <div class="alert alert--warning"><p>Rumus subscore harga, kondisi, seller, dan freshness harus tetap mengikuti konfigurasi backend. Halaman ini hanya mengatur bobot gabungan.</p></div>
        <form method="POST" action="{{ route('admin.ranking.update') }}" data-admin-form>
            @csrf @method('PATCH')
            @if($errors->any())<div class="alert alert--error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <div class="admin-form__grid">
                <div class="form-field form-field--full"><label for="ranking-name">Nama profil</label><input id="ranking-name" name="name" value="{{ old('name', $profile->name) }}" required maxlength="255"></div>
                @foreach([
                    'relevance_weight' => ['Relevansi', 'Kemiripan semantic setelah pemeriksaan atribut.'],
                    'price_weight' => ['Harga', 'Posisi biaya listing terhadap harga pasar.'],
                    'condition_weight' => ['Kondisi', 'Kualitas kondisi berdasarkan informasi seller.'],
                    'seller_weight' => ['Seller', 'Tipe seller terstruktur.'],
                    'freshness_weight' => ['Freshness', 'Usia data sejak sinkronisasi/publikasi.'],
                ] as $field => [$label, $help])
                    <div class="form-field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="number" min="0" max="1" step="0.0001" value="{{ old($field, $profile->{$field}) }}" required><p class="form-help">{{ $help }}</p>@error($field)<p class="form-error">{{ $message }}</p>@enderror</div>
                @endforeach
            </div>
            <div class="ranking-total" data-ranking-total aria-live="polite">Total bobot dihitung saat validasi server.</div>
            <div class="form-actions"><button class="button button--primary" type="submit" data-busy-label="Menyimpan…">Aktifkan Bobot</button></div>
        </form>
    </section>
@endsection
