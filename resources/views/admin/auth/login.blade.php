<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>{{ __('ui.admin.login.title') }} · {{ config('app.name') }}</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body
    class="auth-body"
    data-admin-translations="{{ json_encode(__('ui.admin.js'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
>
    <main class="auth-shell">
        <section class="auth-card" aria-labelledby="login-title">
            <x-locale-switch />
            <div class="auth-card__heading">
                <p class="auth-card__eyebrow">NMTR / RESTRICTED ACCESS</p>
                <h1 id="login-title">{{ config('app.name') }}</h1>
                <p>{{ __('ui.admin.login.description') }}</p>
            </div>

            @if ($errors->any())
                <div class="alert alert--error" role="alert">
                    <p>{{ __('ui.admin.login.failed') }}</p>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form
                class="admin-form"
                method="POST"
                action="{{ route('admin.login.store') }}"
            >
                @csrf

                <div class="form-field">
                    <label for="email">{{ __('ui.admin.login.email') }}</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        value="{{ old('email') }}"
                        required
                        maxlength="255"
                        autocomplete="username"
                        autofocus
                    >
                </div>

                <div class="form-field">
                    <label for="password">{{ __('ui.admin.login.password') }}</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                    >
                </div>

                <button
                    class="button button--primary button--block"
                    type="submit"
                    data-disable-on-submit
                >
                    {{ __('ui.admin.login.submit') }}
                </button>
            </form>

            <a class="auth-card__back" href="{{ route('chatbot') }}">
                {{ __('ui.admin.login.back') }}
            </a>
        </section>
    </main>
</body>
</html>
