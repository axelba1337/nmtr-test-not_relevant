<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\Http\RedirectResponse;

final class ListingRedirectController extends Controller
{
    public function __invoke(Listing $listing): RedirectResponse
    {
        $listing->loadMissing(['platform', 'product.category']);
        abort_unless(
            $listing->is_active
                && $listing->platform?->is_active
                && $listing->platform?->status !== 'disabled'
                && $listing->product?->is_active
                && $listing->product?->category?->is_active,
            404,
        );

        $url = trim((string) $listing->product_url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        abort_unless(
            filter_var($url, FILTER_VALIDATE_URL) !== false
                && in_array($scheme, ['http', 'https'], true)
                && $this->hostIsAllowed($host, $listing),
            404,
        );

        return redirect()->away($url);
    }

    private function hostIsAllowed(string $host, Listing $listing): bool
    {
        if ($host === '') {
            return false;
        }

        if (($listing->raw_payload['demo'] ?? false) === true) {
            return $host === 'example.test';
        }

        $platformHost = strtolower((string) parse_url(
            (string) $listing->platform?->base_url,
            PHP_URL_HOST,
        ));

        return $platformHost !== ''
            && ($host === $platformHost
                || str_ends_with($host, '.'.$platformHost));
    }
}
