<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->json('keywords')->nullable();
            $table->string('source_name')->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();

            $table->index(
                ['category_id', 'is_active'],
                'knowledge_category_active_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropIndex('knowledge_category_active_index');
            $table->dropForeign(['category_id']);
            $table->dropColumn([
                'category_id',
                'keywords',
                'source_name',
                'source_url',
                'embedding_dimensions',
            ]);
        });
    }
};
