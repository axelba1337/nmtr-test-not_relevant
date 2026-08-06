<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                ->constrained()
                ->restrictOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('variant')->nullable();
            $table->string('sku_or_part_number')->nullable();
            $table->string('normalized_key')->unique();
            $table->json('attributes')->nullable();
            $table->json('embedding')->nullable();
            $table->char('embedding_hash', 64)->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->text('embedding_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['category_id', 'is_active'],
                'product_category_active_index',
            );
            $table->index(
                ['category_id', 'brand', 'model'],
                'product_brand_model_index',
            );
            $table->index('variant');
            $table->index('sku_or_part_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
