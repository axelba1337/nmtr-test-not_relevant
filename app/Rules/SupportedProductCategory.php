<?php

namespace App\Rules;

use App\Services\Catalog\CatalogScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class SupportedProductCategory implements ValidationRule
{
    public function __construct(
        private readonly CatalogScope $scope,
    ) {}

    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_numeric($value) || ! $this->scope->supportsId((int) $value)) {
            $fail(__('ui.validation.custom.unsupported_product_category'));
        }
    }
}
