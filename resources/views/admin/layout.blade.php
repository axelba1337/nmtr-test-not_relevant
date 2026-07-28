<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · {{ config('app.name') }}</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="admin-body">
    <a class="skip-link" href="#admin-content">Lewati ke konten utama</a>

    <div class="admin-shell" data-admin-shell>
        <aside
            class="admin-sidebar"
            id="admin-sidebar"
            aria-label="Navigasi admin"
            data-admin-sidebar
        >
            <div class="admin-sidebar__brand">
                <span class="admin-sidebar__eyebrow">Panel Admin</span>
                <span class="admin-sidebar__name">{{ config('app.name') }}</span>
            </div>

            <nav class="admin-nav" aria-label="Menu utama">
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}"
                    href="{{ route('admin.dashboard') }}"
                    @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif
                >
                    Dashboard
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.knowledge.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.knowledge.index') }}"
                    @if (request()->routeIs('admin.knowledge.*')) aria-current="page" @endif
                >
                    FAQ / Knowledge
                </a>
            </nav>

            <form class="admin-sidebar__logout" method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="admin-nav__link admin-nav__button" type="submit">
                    Logout
                </button>
            </form>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <div class="admin-topbar__heading">
                    <button
                        class="admin-menu-button"
                        type="button"
                        aria-controls="admin-sidebar"
                        aria-expanded="false"
                        data-admin-menu-toggle
                    >
                        Menu
                    </button>
                    <div>
                        <p class="admin-topbar__eyebrow">Administrasi</p>
                        <h1>@yield('page-title', 'Panel Admin')</h1>
                    </div>
                </div>

                <div class="admin-identity">
                    <span class="admin-identity__label">Masuk sebagai</span>
                    <strong>{{ auth()->user()?->name }}</strong>
                </div>
            </header>

            <main class="admin-content" id="admin-content">
                @foreach (['success', 'warning', 'error'] as $flashType)
                    @if (session()->has($flashType))
                        <div
                            class="alert alert--{{ $flashType }}"
                            role="{{ $flashType === 'error' ? 'alert' : 'status' }}"
                        >
                            {{ session($flashType) }}
                        </div>
                    @endif
                @endforeach

                @yield('content')
            </main>
        </div>
    </div>
</body>
</html>
