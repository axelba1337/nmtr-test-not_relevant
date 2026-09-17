<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/branding/neomatra-logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/branding/neomatra-logo.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-brand-area="user">
    <main class="public-page-shell">
        <article class="public-page-card">
            <header class="public-page-header">
                <a class="chat-brand public-page-brand" href="{{ route('chatbot') }}" data-brand-lock>
                    <img
                        class="chat-brand__logo"
                        src="{{ asset('images/branding/neomatra-logo.png') }}"
                        alt=""
                        aria-hidden="true"
                        data-brand-mark
                    >
                    <span class="chat-brand__copy" data-brand-copy>
                        <span class="eyebrow">{{ __('ui.public.eyebrow') }}</span>
                        <strong>{{ config('app.name') }}</strong>
                    </span>
                </a>
                <x-locale-switch />
            </header>

            <div class="public-page-content">
                @yield('content')
            </div>
        </article>
    </main>
</body>
</html>
