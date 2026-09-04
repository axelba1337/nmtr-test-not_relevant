<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Rules\SafeProductAliases;
use App\Services\Catalog\CatalogScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class LinkExistingProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'aliases' => [
                'nullable',
                'string',
                'max:10000',
                new SafeProductAliases,
            ],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $product = Product::query()
                ->with('category.parent.parent')
                ->find($this->integer('product_id'));

            if ($product instanceof Product
                && ! app(CatalogScope::class)->supports($product->category)) {
                $validator->errors()->add(
                    'product_id',
                    __('ui.validation.custom.unsupported_product_category'),
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $aliases = $this->input('aliases');

        $this->merge([
            'aliases' => is_string($aliases) && trim($aliases) !== ''
                ? trim($aliases)
                : null,
        ]);
    }
}
