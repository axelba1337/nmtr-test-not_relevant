<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SafeProductAliases implements ValidationRule
{
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $aliases = preg_split('/[\r\n,]+/u', $value) ?: [];
        $noisePattern = '/\b(murah|ready(?:\s+stock)?|like\s+new|full\s*set|fullset|box\s+only|termurah|nego|free\s+ongkir|jasa|service|wanted|dicari|bundle|bundling)\b/iu';

        foreach ($aliases as $alias) {
            $alias = trim($alias);

            if ($alias === '') {
                continue;
            }

            if (preg_match('/[\pL\pN]/u', $alias) !== 1
                || preg_match($noisePattern, $alias) === 1) {
                $fail(__('ui.validation.custom.product_alias_noisy'));

                return;
            }
        }
    }
}
