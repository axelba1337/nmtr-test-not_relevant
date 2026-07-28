<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->boolean('is_featured')
                ->default(false)
                ->after('is_active');
            $table->unsignedInteger('sort_order')
                ->default(0)
                ->after('is_featured');
            $table->string('embedding_model')
                ->nullable()
                ->after('embedding_hash');
            $table->timestamp('embedded_at')
                ->nullable()
                ->after('embedding_model');
            $table->text('embedding_error')
                ->nullable()
                ->after('embedded_at');
            $table->index(
                ['is_active', 'is_featured', 'sort_order', 'id'],
                'knowledge_suggestions_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_items', function (Blueprint $table) {
            $table->dropIndex('knowledge_suggestions_index');
            $table->dropColumn([
                'is_featured',
                'sort_order',
                'embedding_model',
                'embedded_at',
                'embedding_error',
            ]);
        });
    }
};
