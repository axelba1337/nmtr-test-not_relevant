<?php

namespace App\Data\Search;

use App\Models\Category;

final readonly class CategoryResolution
{
    public function __construct(
        public Category $category,
        public string $matchedAlias,
        public string $strategy = 'category_alias',
    ) {}
}
