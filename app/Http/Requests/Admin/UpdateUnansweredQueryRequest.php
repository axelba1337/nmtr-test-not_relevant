<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUnansweredQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reviewed' => ['required', 'boolean'],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.unanswered');
    }

    protected function prepareForValidation(): void
    {
        $notes = trim((string) $this->input('review_notes', ''));

        $this->merge([
            'reviewed' => $this->boolean('reviewed'),
            'review_notes' => $notes !== '' ? $notes : null,
        ]);
    }
}
