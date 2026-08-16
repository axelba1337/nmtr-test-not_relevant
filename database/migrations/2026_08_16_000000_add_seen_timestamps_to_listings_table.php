<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->timestamp('first_seen_at')
                ->nullable()
                ->after('source_published_at');
            $table->timestamp('last_seen_at')
                ->nullable()
                ->after('first_seen_at');
            $table->index(
                ['platform_id', 'is_active', 'last_seen_at'],
                'listing_platform_active_seen_index',
            );
        });

        DB::table('listings')->update([
            'first_seen_at' => DB::raw(
                'COALESCE(created_at, last_synced_at, updated_at)',
            ),
            'last_seen_at' => DB::raw(
                'COALESCE(last_synced_at, updated_at, created_at)',
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('listing_platform_active_seen_index');
            $table->dropColumn(['first_seen_at', 'last_seen_at']);
        });
    }
};
