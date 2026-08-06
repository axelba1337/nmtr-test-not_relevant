<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Perbandingan {{ $product->name }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="comparison-page">
    <main class="comparison-shell">
        <a class="comparison-back" href="{{ route('chatbot') }}">← Kembali ke chatbot</a>
        <header class="comparison-header">
            <p class="eyebrow">PERBANDINGAN LINTAS PLATFORM</p>
            <h1>{{ $product->name }}</h1>
            <p>
                {{ collect([$product->brand, $product->model, $product->variant])->filter()->join(' · ') }}
            </p>
        </header>

        <div class="comparison-notice" role="note">
            Harga, kondisi, seller, dan estimasi merupakan informasi dari data sumber/demo, bukan jaminan transaksi.
        </div>

        @if ($listings === [])
            <section class="comparison-empty">
                <h2>Belum ada listing pembanding</h2>
                <p>Aktifkan atau sinkronkan listing untuk produk ini melalui panel admin.</p>
            </section>
        @else
            <div class="comparison-table-wrap">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Listing</th>
                            <th>Harga</th>
                            <th>Total biaya</th>
                            <th>Harga pasar</th>
                            <th>Undervalued</th>
                            <th>Seller / Lokasi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($listings as $listing)
                            <tr>
                                <td>{{ $listing['platform'] }}</td>
                                <td>
                                    <strong>{{ $listing['title'] }}</strong>
                                    <small>{{ $listing['condition'] }} · berdasarkan keterangan seller</small>
                                </td>
                                <td>Rp{{ number_format($listing['item_price'], 0, ',', '.') }}</td>
                                <td>Rp{{ number_format($listing['costs']['total'], 0, ',', '.') }}</td>
                                <td>
                                    {{ $listing['market']['price'] === null ? 'Belum cukup data' : 'Rp'.number_format($listing['market']['price'], 0, ',', '.') }}
                                </td>
                                <td>
                                    @if ($listing['undervalued'] === null)
                                        —
                                    @else
                                        {{ number_format($listing['undervalued']['percentage'], 1, ',', '.') }}%
                                        <small>{{ $listing['undervalued']['label'] }}</small>
                                    @endif
                                </td>
                                <td>
                                    {{ $listing['seller_name'] ?: 'Tidak diketahui' }}
                                    <small>{{ $listing['location'] ?: 'Lokasi tidak tersedia' }}</small>
                                </td>
                                <td>
                                    <a href="{{ $listing['source_url'] }}" target="_blank" rel="noopener noreferrer">Buka sumber</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </main>
</body>
</html>
