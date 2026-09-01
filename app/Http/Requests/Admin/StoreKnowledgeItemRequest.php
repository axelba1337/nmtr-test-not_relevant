<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:50000'],
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:100'],
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url:http,https', 'max:2048'],
            'is_active' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => [
                'required',
                'integer',
                'min:0',
                'max:4294967295',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('ui.validation.attributes.knowledge');
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $content = $this->input('content');
        $sourceName = $this->input('source_name');
        $sourceUrl = $this->input('source_url');
        $keywords = $this->input('keywords');

        if (is_string($keywords)) {
            $values = preg_split('/[\r\n,]+/u', $keywords) ?: [];
            $keywords = array_values(array_unique(array_filter(array_map(
                static fn (string $value): string => trim($value),
                $values,
            ))));
        }

        $this->merge([
            'title' => is_string($title) ? trim($title) : $title,
            'content' => is_string($content) ? trim($content) : $content,
            'category_id' => $this->filled('category_id')
                ? $this->input('category_id')
                : null,
            'keywords' => $keywords === [] ? null : $keywords,
            'source_name' => is_string($sourceName) && trim($sourceName) !== ''
                ? trim($sourceName)
                : null,
            'source_url' => is_string($sourceUrl) && trim($sourceUrl) !== ''
                ? trim($sourceUrl)
                : null,
            'is_active' => $this->boolean('is_active'),
            'is_featured' => $this->boolean('is_featured'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
