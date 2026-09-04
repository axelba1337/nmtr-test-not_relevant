<?php

namespace App\Http\Requests\Admin;

use App\Rules\SafeProductAliases;
use App\Rules\SupportedProductCategory;
use App\Services\Catalog\CatalogScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
                new SupportedProductCategory(app(CatalogScope::class)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'variant' => ['nullable', 'string', 'max:255'],
            'sku_or_part_number' => ['nullable', 'string', 'max:255'],
            'attributes' => ['nullable', 'string', 'json', 'max:20000'],
            'spec_source_name' => ['nullable', 'string', 'max:120'],
            'spec_source_url' => ['nullable', 'url', 'max:2048'],
            'spec_source_checked_at' => ['nullable', 'date'],
            'aliases' => [
                'nullable',
                'string',
                'max:10000',
                new SafeProductAliases,
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $attributes = $this->input('attributes');

            if (is_string($attributes)
                && $attributes !== ''
                && ! is_array(json_decode($attributes, true))) {
                $validator->errors()->add(
                    'attributes',
                    __('ui.validation.custom.product_attributes_object'),
                );
            }

            $aliases = preg_split(
                '/[\r\n,]+/u',
                (string) $this->input('aliases', ''),
            ) ?: [];

            foreach ($aliases as $alias) {
                if (mb_strlen(trim($alias), 'UTF-8') > 255) {
                    $validator->errors()->add(
                        'aliases',
                        __('ui.validation.custom.product_alias_max'),
                    );

                    break;
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.product');
    }

    protected function prepareForValidation(): void
    {
        $nullable = static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = trim($value);

            return $value !== '' ? $value : null;
        };

        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'brand' => $nullable($this->input('brand')),
            'model' => $nullable($this->input('model')),
            'variant' => $nullable($this->input('variant')),
            'sku_or_part_number' => $nullable($this->input('sku_or_part_number')),
            'attributes' => $nullable($this->input('attributes')),
            'spec_source_name' => $nullable($this->input('spec_source_name')),
            'spec_source_url' => $nullable($this->input('spec_source_url')),
            'spec_source_checked_at' => $nullable(
                $this->input('spec_source_checked_at'),
            ),
            'aliases' => $nullable($this->input('aliases')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
