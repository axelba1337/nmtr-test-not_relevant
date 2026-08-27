<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Login Admin · {{ config('app.name') }}</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-card" aria-labelledby="login-title">
            <div class="auth-card__heading">
                <p class="auth-card__eyebrow">NMTR / RESTRICTED ACCESS</p>
                <h1 id="login-title">{{ config('app.name') }}</h1>
                <p>Masuk untuk mengelola FAQ dan memantau aktivitas chatbot.</p>
            </div>

            @if ($errors->any())
                <div class="alert alert--error" role="alert">
                    <p>Login belum berhasil:</p>
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
                    <label for="email">Email</label>
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
                    <label for="password">Password</label>
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
                    Masuk
                </button>
            </form>

            <a class="auth-card__back" href="{{ route('chatbot') }}">
                Kembali ke chatbot
            </a>
        </section>
    </main>
</body>
</html>
