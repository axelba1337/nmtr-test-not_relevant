<?php

namespace App\Http\Requests\Admin;

use App\Models\Platform;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        $platform = $this->route('platform');

        return (bool) $this->user()?->is_admin
            && $platform instanceof Platform
            && $platform->isMvpSource();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_url' => ['nullable', 'url:http,https', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.platform');
    }

    protected function prepareForValidation(): void
    {
        $baseUrl = trim((string) $this->input('base_url', ''));

        $this->merge([
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }
}
