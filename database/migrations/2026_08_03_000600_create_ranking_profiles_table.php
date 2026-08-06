<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ranking_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('relevance_weight', 5, 4)->default(0.4000);
            $table->decimal('price_weight', 5, 4)->default(0.3000);
            $table->decimal('condition_weight', 5, 4)->default(0.1500);
            $table->decimal('seller_weight', 5, 4)->default(0.1000);
            $table->decimal('freshness_weight', 5, 4)->default(0.0500);
            $table->boolean('is_active')->default(false)->index();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranking_profiles');
    }
};
