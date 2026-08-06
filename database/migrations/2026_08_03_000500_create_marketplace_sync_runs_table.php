<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('initiated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('mode', 24)->default('manual');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_skipped')->default(0);
            $table->unsignedInteger('records_failed')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['platform_id', 'status', 'created_at'],
                'sync_platform_status_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_sync_runs');
    }
};
