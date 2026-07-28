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
        return [
            'title' => 'pertanyaan',
            'content' => 'jawaban',
            'is_active' => 'status aktif',
            'is_featured' => 'status featured',
            'sort_order' => 'urutan',
        ];
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title');
        $content = $this->input('content');

        $this->merge([
            'title' => is_string($title) ? trim($title) : $title,
            'content' => is_string($content) ? trim($content) : $content,
            'is_active' => $this->boolean('is_active'),
            'is_featured' => $this->boolean('is_featured'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
