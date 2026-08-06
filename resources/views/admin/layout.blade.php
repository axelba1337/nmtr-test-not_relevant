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
                <p class="admin-nav__label">Ringkasan</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}"
                    href="{{ route('admin.dashboard') }}"
                    @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif
                >
                    Dashboard
                </a>
                <p class="admin-nav__label admin-nav__label--spaced">Katalog</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.categories.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.categories.index') }}"
                    @if (request()->routeIs('admin.categories.*')) aria-current="page" @endif
                >
                    Kategori
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.products.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.products.index') }}"
                    @if (request()->routeIs('admin.products.*')) aria-current="page" @endif
                >
                    Produk &amp; Alias
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.platforms.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.platforms.index') }}"
                    @if (request()->routeIs('admin.platforms.*')) aria-current="page" @endif
                >
                    Platform
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.listings.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.listings.index') }}"
                    @if (request()->routeIs('admin.listings.*')) aria-current="page" @endif
                >
                    Listing
                </a>
                <p class="admin-nav__label admin-nav__label--spaced">AI &amp; Evaluasi</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.knowledge.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.knowledge.index') }}"
                    @if (request()->routeIs('admin.knowledge.*')) aria-current="page" @endif
                >
                    Knowledge Base
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.unanswered.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.unanswered.index') }}"
                    @if (request()->routeIs('admin.unanswered.*')) aria-current="page" @endif
                >
                    Unanswered Queries
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.ranking.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.ranking.edit') }}"
                    @if (request()->routeIs('admin.ranking.*')) aria-current="page" @endif
                >
                    Bobot Ranking
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
