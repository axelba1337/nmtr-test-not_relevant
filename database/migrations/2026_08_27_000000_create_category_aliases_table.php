<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->string('language', 10)->default('und');
            $table->string('source', 50)->default('curated');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['category_id', 'normalized_alias'],
                'category_alias_unique',
            );
            $table->index(
                ['normalized_alias', 'is_active'],
                'category_alias_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_aliases');
    }
};
