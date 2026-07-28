<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_query_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32);
            $table->decimal('top1_similarity', 12, 9)->nullable();
            $table->timestamps();
            $table->index('created_at');
            $table->index(
                ['status', 'created_at'],
                'chatbot_query_status_created_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_query_logs');
    }
};
