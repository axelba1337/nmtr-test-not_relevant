<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_query_logs', function (Blueprint $table) {
            $table->string('response_mode', 32)->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->string('failure_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_query_logs', function (Blueprint $table) {
            $table->dropColumn([
                'response_mode',
                'prompt_tokens',
                'output_tokens',
                'total_tokens',
                'failure_code',
            ]);
        });
    }
};
