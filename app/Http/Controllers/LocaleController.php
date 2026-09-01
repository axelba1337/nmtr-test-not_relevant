<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class LocaleController extends Controller
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        $locale = in_array($locale, SetLocale::SUPPORTED, true)
            ? $locale
            : 'id';

        $request->session()->put('locale', $locale);

        return back()->withCookie(cookie()->forever(
            'neomatra_locale',
            $locale,
            config('session.path', '/'),
            config('session.domain'),
            (bool) config('session.secure', false),
            (bool) config('session.http_only', true),
            false,
            (string) config('session.same_site', 'lax'),
        ));
    }
}
