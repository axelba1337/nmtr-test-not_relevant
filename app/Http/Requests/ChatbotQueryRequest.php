<?php

namespace App\Http\Requests;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChatbotQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'budget_max' => ['nullable', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:120'],
            'platform' => ['nullable', 'string', 'max:120'],
            'condition' => [
                'nullable',
                Rule::in(Listing::conditions()),
            ],
            'session_key' => ['nullable', 'uuid'],
            'history' => ['nullable', 'array', 'max:6'],
            'history.*' => ['required', 'array:role,content'],
            'history.*.role' => [
                'required',
                'string',
                Rule::in(['user', 'assistant']),
            ],
            'history.*.content' => [
                'required',
                'string',
                'max:4000',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('ui.validation.attributes.chatbot');
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');
        $location = $this->input('location');
        $budget = $this->input('budget_max');

        $this->merge([
            'message' => is_string($message) ? trim($message) : $message,
            'location' => is_string($location) && trim($location) !== ''
                ? trim($location)
                : null,
            'platform' => $this->nullableString('platform'),
            'condition' => $this->nullableString('condition'),
            'budget_max' => is_numeric($budget)
                ? (int) $budget
                : $budget,
        ]);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
