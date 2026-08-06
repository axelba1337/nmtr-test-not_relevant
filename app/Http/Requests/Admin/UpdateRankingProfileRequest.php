<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateRankingProfileRequest extends FormRequest
{
    private const WEIGHT_FIELDS = [
        'relevance_weight',
        'price_weight',
        'condition_weight',
        'seller_weight',
        'freshness_weight',
    ];

    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'relevance_weight' => ['required', 'numeric', 'between:0,1'],
            'price_weight' => ['required', 'numeric', 'between:0,1'],
            'condition_weight' => ['required', 'numeric', 'between:0,1'],
            'seller_weight' => ['required', 'numeric', 'between:0,1'],
            'freshness_weight' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $sum = array_reduce(
                self::WEIGHT_FIELDS,
                fn (float $total, string $field): float => $total
                    + (float) $this->input($field, 0),
                0.0,
            );

            if (abs($sum - 1.0) > 0.0001) {
                $validator->errors()->add(
                    'relevance_weight',
                    'Total seluruh bobot harus tepat 1,00.',
                );
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
        ]);
    }
}
