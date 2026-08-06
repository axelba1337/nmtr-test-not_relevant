<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_query_logs', function (Blueprint $table) {
            $table->string('session_key', 64)->nullable()->index();
            $table->text('query_text')->nullable();
            $table->text('normalized_query')->nullable();
            $table->json('intent')->nullable();
            $table->json('filters')->nullable();
            $table->unsignedSmallInteger('result_count')->default(0);
            $table->boolean('is_unanswered')->default(false);
            $table->string('unanswered_reason', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('review_notes')->nullable();

            $table->index(
                ['is_unanswered', 'reviewed_at', 'created_at'],
                'query_unanswered_review_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_query_logs', function (Blueprint $table) {
            $table->dropIndex('query_unanswered_review_index');
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn([
                'session_key',
                'query_text',
                'normalized_query',
                'intent',
                'filters',
                'result_count',
                'is_unanswered',
                'unanswered_reason',
                'reviewed_at',
                'reviewed_by',
                'review_notes',
            ]);
        });
    }
};
