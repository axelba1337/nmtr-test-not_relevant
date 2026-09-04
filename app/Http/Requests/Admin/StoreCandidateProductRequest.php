<?php

namespace App\Http\Requests\Admin;

use App\Rules\SafeProductAliases;
use App\Rules\SupportedProductCategory;
use App\Services\Catalog\CatalogScope;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCandidateProductRequest extends FormRequest
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
            'spec_source_name' => ['nullable', 'string', 'max:120'],
            'spec_source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'spec_source_checked_at' => ['nullable', 'date'],
            'aliases' => [
                'nullable',
                'string',
                'max:10000',
                new SafeProductAliases,
            ],
        ];
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
            'spec_source_name' => $nullable($this->input('spec_source_name')),
            'spec_source_url' => $nullable($this->input('spec_source_url')),
            'spec_source_checked_at' => $nullable(
                $this->input('spec_source_checked_at'),
            ),
            'aliases' => $nullable($this->input('aliases')),
        ]);
    }
}
