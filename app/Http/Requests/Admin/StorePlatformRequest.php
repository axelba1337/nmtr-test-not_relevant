<?php

namespace App\Http\Requests\Admin;

use App\Models\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StorePlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $platform = $this->route('platform');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('platforms', 'slug')->ignore($platform),
            ],
            'source_type' => ['required', Rule::in(Platform::sourceTypes())],
            'access_type' => ['required', Rule::in(Platform::accessTypes())],
            'connector' => [
                'nullable',
                'string',
                'max:255',
                'regex:/\A[a-z0-9._-]+\z/',
            ],
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'status' => ['required', Rule::in(Platform::statuses())],
            'is_active' => ['required', 'boolean'],
            'sync_enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.platform');
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $slug = trim((string) $this->input('slug', ''));
        $connector = trim((string) $this->input('connector', ''));
        $baseUrl = trim((string) $this->input('base_url', ''));

        $this->merge([
            'name' => $name,
            'slug' => Str::slug($slug !== '' ? $slug : $name),
            'connector' => $connector !== '' ? $connector : null,
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'is_active' => $this->boolean('is_active'),
            'sync_enabled' => $this->boolean('sync_enabled'),
            'status' => $this->input('status', Platform::STATUS_READY),
        ]);
    }
}
