<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('chatbot_query_logs', 'web_source_count')) {
            Schema::table('chatbot_query_logs', function (Blueprint $table) {
                $table->dropColumn('web_source_count');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('chatbot_query_logs', 'web_source_count')) {
            Schema::table('chatbot_query_logs', function (Blueprint $table) {
                $table->unsignedSmallInteger('web_source_count')->default(0);
            });
        }
    }
};
