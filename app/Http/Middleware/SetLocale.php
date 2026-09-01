<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

final class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $provided = $request->input('locale')
            ?? $request->header('X-Neomatra-Locale');

        if ($provided !== null) {
            $locale = $this->valid($provided) ? $provided : 'id';
        } else {
            $sessionLocale = $request->hasSession()
                ? $request->session()->get('locale')
                : null;
            $locale = $this->valid($sessionLocale)
                ? $sessionLocale
                : $request->cookie('neomatra_locale');
            $locale = $this->valid($locale) ? $locale : 'id';
        }

        App::setLocale($locale);

        return $next($request);
    }

    private function valid(mixed $locale): bool
    {
        return is_string($locale)
            && in_array($locale, self::SUPPORTED, true);
    }
}
