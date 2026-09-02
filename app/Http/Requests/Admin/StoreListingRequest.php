<?php

namespace App\Http\Requests\Admin;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $listing = $this->route('listing');
        $platformId = $this->integer('platform_id');
        $externalIdRule = Rule::unique('listings', 'external_listing_id')
            ->where(fn ($query) => $query->where('platform_id', $platformId))
            ->ignore($listing);

        return [
            'platform_id' => ['required', 'integer', 'exists:platforms,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'external_listing_id' => [
                'nullable',
                'string',
                'max:255',
                $externalIdRule,
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:50000'],
            'price' => ['required', 'integer', 'min:1', 'max:'.Listing::MAX_MONEY],
            'currency' => ['required', 'string', 'size:3', 'alpha:ascii'],
            'condition' => ['required', Rule::in(Listing::conditions())],
            'seller_name' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'product_url' => ['required', 'url:http,https', 'max:2048'],
            'image_url' => ['nullable', 'url:http,https', 'max:2048'],
            'stock_status' => ['required', Rule::in(Listing::stockStatuses())],
            'price_type' => ['required', Rule::in(Listing::priceTypes())],
            'warranty' => ['nullable', 'string', 'max:255'],
            'raw_payload' => ['nullable', 'string', 'json', 'max:500000'],
            'source_published_at' => ['nullable', 'date'],
            'last_synced_at' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $payload = $this->input('raw_payload');

            if (! is_string($payload) || $payload === '') {
                return;
            }

            if (! is_array(json_decode($payload, true))) {
                $validator->errors()->add(
                    'raw_payload',
                    __('ui.validation.custom.raw_payload_object'),
                );
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.listing');
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
            'product_id' => $this->filled('product_id') ? $this->input('product_id') : null,
            'external_listing_id' => $nullable($this->input('external_listing_id')),
            'title' => trim((string) $this->input('title', '')),
            'description' => $nullable($this->input('description')),
            'currency' => strtoupper(trim((string) $this->input('currency', 'IDR'))),
            'seller_name' => $nullable($this->input('seller_name')),
            'location' => $nullable($this->input('location')),
            'product_url' => trim((string) $this->input('product_url', '')),
            'image_url' => $nullable($this->input('image_url')),
            'warranty' => $nullable($this->input('warranty')),
            'raw_payload' => $nullable($this->input('raw_payload')),
            'source_published_at' => $nullable($this->input('source_published_at')),
            'last_synced_at' => $nullable($this->input('last_synced_at')),
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
