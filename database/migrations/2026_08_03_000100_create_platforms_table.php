<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('source_type', 32);
            $table->string('access_type', 32);
            $table->string('connector')->nullable();
            $table->string('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('sync_enabled')->default(false);
            $table->string('status', 32)->default('ready');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(
                ['is_active', 'sync_enabled', 'status'],
                'platform_availability_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platforms');
    }
};
