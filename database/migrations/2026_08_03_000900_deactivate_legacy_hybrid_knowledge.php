<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Legacy generic-chatbot records are retained for auditability but are no
     * longer eligible for retrieval under the revised Neomatra SRS.
     */
    public function up(): void
    {
        DB::table('knowledge_items')
            ->whereIn('title', [
                'Tentang Semantic Chatbot Lite',
                'Ketika informasi tidak tersedia',
                'Cara kerja semantic retrieval',
            ])
            ->update([
                'is_active' => false,
                'is_featured' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Status sebelumnya tidak dapat dipulihkan secara aman.
    }
};
