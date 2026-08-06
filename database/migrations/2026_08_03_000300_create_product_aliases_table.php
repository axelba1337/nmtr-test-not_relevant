<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->json('embedding')->nullable();
            $table->char('embedding_hash', 64)->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedSmallInteger('embedding_dimensions')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->text('embedding_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['product_id', 'normalized_alias'],
                'product_alias_unique',
            );
            $table->index('normalized_alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
    }
};
