<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/branding/neomatra-logo.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/branding/neomatra-logo.png') }}">
    <title>@yield('title', __('ui.admin.title')) · {{ config('app.name') }}</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body
    class="admin-body"
    data-brand-area="admin"
    data-admin-translations="{{ json_encode(__('ui.admin.js'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}"
>
    <a class="skip-link" href="#admin-content">{{ __('ui.admin.skip') }}</a>

    <div class="admin-shell" data-admin-shell>
        <aside
            class="admin-sidebar"
            id="admin-sidebar"
            aria-label="{{ __('ui.admin.navigation') }}"
            data-admin-sidebar
        >
            <div class="admin-sidebar__brand" data-brand-lock>
                <img
                    class="admin-sidebar__logo"
                    src="{{ asset('images/branding/neomatra-logo.png') }}"
                    alt=""
                    aria-hidden="true"
                    data-brand-mark
                >
                <span class="admin-sidebar__brand-copy" data-brand-copy>
                    <span class="admin-sidebar__eyebrow">NMTR / CONTROL</span>
                    <span class="admin-sidebar__name">{{ config('app.name') }}</span>
                </span>
            </div>

            <nav class="admin-nav" aria-label="{{ __('ui.admin.main_menu') }}">
                @php
                    $listingAutomaticActive = request()->routeIs('admin.listings.discovery*');
                    $listingImportActive = request()->routeIs(
                        'admin.listings.import*',
                        'admin.listings.coverage*',
                    );
                    $listingDataActive = request()->routeIs(
                        'admin.listings.index',
                        'admin.listings.create',
                        'admin.listings.edit',
                        'admin.listings.unmatched',
                        'admin.listings.candidate.*',
                    );
                    $listingSectionActive = $listingAutomaticActive
                        || $listingImportActive
                        || $listingDataActive;
                @endphp
                <p class="admin-nav__label">{{ __('ui.admin.summary') }}</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}"
                    href="{{ route('admin.dashboard') }}"
                    @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif
                >
                    {{ __('ui.admin.dashboard') }}
                </a>
                <p class="admin-nav__label admin-nav__label--spaced">{{ __('ui.admin.catalog') }}</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.categories.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.categories.index') }}"
                    @if (request()->routeIs('admin.categories.*')) aria-current="page" @endif
                >
                    {{ __('ui.admin.categories') }}
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.products.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.products.index') }}"
                    @if (request()->routeIs('admin.products.*')) aria-current="page" @endif
                >
                    {{ __('ui.admin.products_aliases') }}
                </a>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.platforms.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.platforms.index') }}"
                    @if (request()->routeIs('admin.platforms.*')) aria-current="page" @endif
                >
                    {{ __('ui.admin.platforms') }}
                </a>
                <details
                    class="admin-nav__group"
                    data-admin-listing-group
                    data-admin-listing-expanded="{{ $listingSectionActive ? 'true' : 'false' }}"
                    @if($listingSectionActive) open @endif
                >
                    <summary class="admin-nav__link admin-nav__parent {{ $listingSectionActive ? 'is-active' : '' }}">
                        {{ __('ui.admin.listings') }}
                    </summary>
                    <div class="admin-nav__submenu">
                        <a
                            class="admin-nav__link admin-nav__sublink {{ $listingDataActive ? 'is-active' : '' }}"
                            href="{{ route('admin.listings.index') }}"
                            data-admin-nav-child="listing-data"
                            data-admin-nav-active="{{ $listingDataActive ? 'true' : 'false' }}"
                            @if($listingDataActive) aria-current="page" @endif
                        >
                            {{ __('ui.admin.listing_data') }}
                        </a>
                        <a
                            class="admin-nav__link admin-nav__sublink {{ $listingAutomaticActive ? 'is-active' : '' }}"
                            href="{{ route('admin.listings.discovery') }}"
                            data-admin-nav-child="automatic-listing"
                            data-admin-nav-active="{{ $listingAutomaticActive ? 'true' : 'false' }}"
                            @if($listingAutomaticActive) aria-current="page" @endif
                        >
                            {{ __('ui.admin.automatic_listing') }}
                        </a>
                        <a
                            class="admin-nav__link admin-nav__sublink {{ $listingImportActive ? 'is-active' : '' }}"
                            href="{{ route('admin.listings.import') }}"
                            data-admin-nav-child="import-csv"
                            data-admin-nav-active="{{ $listingImportActive ? 'true' : 'false' }}"
                            @if($listingImportActive) aria-current="page" @endif
                        >
                            {{ __('ui.admin.import_csv') }}
                        </a>
                    </div>
                </details>
                <p class="admin-nav__label admin-nav__label--spaced">{{ __('ui.admin.ai_evaluation') }}</p>
                <a
                    class="admin-nav__link {{ request()->routeIs('admin.knowledge.*') ? 'is-active' : '' }}"
                    href="{{ route('admin.knowledge.index') }}"
                    @if (request()->routeIs('admin.knowledge.*')) aria-current="page" @endif
                >
                    {{ __('ui.admin.knowledge') }}
                </a>
            </nav>

            <form class="admin-sidebar__logout" method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="admin-nav__link admin-nav__button" type="submit">
                    {{ __('ui.admin.logout') }}
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
                        {{ __('ui.admin.menu') }}
                    </button>
                    <div>
                        <p class="admin-topbar__eyebrow">ADMINISTRATION / CONTROL PLANE</p>
                        <h1>@yield('page-title', __('ui.admin.panel'))</h1>
                    </div>
                </div>

                <div class="admin-topbar__actions">
                    <x-locale-switch />
                    <div class="admin-identity">
                        <span class="admin-identity__label">{{ __('ui.admin.signed_in_as') }}</span>
                        <strong>{{ auth()->user()?->name }}</strong>
                    </div>
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
