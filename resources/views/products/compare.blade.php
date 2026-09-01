<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ __('ui.compare.title', ['product' => $product->name]) }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="comparison-page">
    <main class="comparison-shell">
        <div class="comparison-toolbar">
            <a class="comparison-back" href="{{ route('chatbot') }}">{{ __('ui.compare.back') }}</a>
            <x-locale-switch />
        </div>
        <header class="comparison-header">
            <p class="eyebrow">{{ __('ui.compare.eyebrow') }}</p>
            <h1>{{ $product->name }}</h1>
            <p>
                {{ collect([$product->brand, $product->model, $product->variant])->filter()->join(' · ') }}
            </p>
        </header>

        <div class="comparison-notice" role="note">
            {{ __('ui.compare.notice') }}
        </div>

        @if ($listings === [])
            <section class="comparison-empty">
                <h2>{{ __('ui.compare.empty_title') }}</h2>
                <p>{{ __('ui.compare.empty_body') }}</p>
            </section>
        @else
            <div class="comparison-table-wrap">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>{{ __('ui.compare.platform') }}</th>
                            <th>{{ __('ui.compare.listing') }}</th>
                            <th>{{ __('ui.compare.price') }}</th>
                            <th>{{ __('ui.compare.total_cost') }}</th>
                            <th>{{ __('ui.compare.market_price') }}</th>
                            <th>{{ __('ui.compare.undervalued') }}</th>
                            <th>{{ __('ui.compare.seller_location') }}</th>
                            <th>{{ __('ui.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($listings as $listing)
                            <tr>
                                <td>{{ $listing['platform'] }}</td>
                                <td>
                                    <strong>{{ $listing['title'] }}</strong>
                                    <small>{{ __('ui.labels.'.$listing['condition']) }} · {{ __('ui.compare.seller_basis') }}</small>
                                </td>
                                <td>Rp{{ number_format($listing['item_price'], 0, ',', '.') }}</td>
                                <td>Rp{{ number_format($listing['costs']['total'], 0, ',', '.') }}</td>
                                <td>
                                    {{ $listing['market']['price'] === null ? __('ui.compare.insufficient_data') : 'Rp'.number_format($listing['market']['price'], 0, ',', '.') }}
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
                                    {{ $listing['seller_name'] ?: __('ui.common.unknown') }}
                                    <small>{{ $listing['location'] ?: __('ui.compare.unknown_location') }}</small>
                                </td>
                                <td>
                                    <a href="{{ $listing['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ __('ui.common.open_source') }}</a>
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
