<?php

namespace App\Http\Requests\Admin;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash:ascii',
                Rule::unique('categories', 'slug')->ignore($category),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $category = $this->route('category');
            $parentId = $this->integer('parent_id');

            if (! $category instanceof Category || $parentId < 1) {
                return;
            }

            $visited = [];

            while ($parentId > 0 && ! isset($visited[$parentId])) {
                if ($parentId === (int) $category->id) {
                    $validator->errors()->add(
                        'parent_id',
                        'Kategori induk tidak boleh membentuk siklus.',
                    );

                    return;
                }

                $visited[$parentId] = true;
                $parentId = (int) (Category::query()
                    ->whereKey($parentId)
                    ->value('parent_id') ?? 0);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $name = trim((string) $this->input('name', ''));
        $slug = trim((string) $this->input('slug', ''));
        $description = trim((string) $this->input('description', ''));

        $this->merge([
            'name' => $name,
            'slug' => Str::slug($slug !== '' ? $slug : $name),
            'description' => $description !== '' ? $description : null,
            'parent_id' => $this->filled('parent_id') ? $this->input('parent_id') : null,
            'is_active' => $this->boolean('is_active'),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }
}
