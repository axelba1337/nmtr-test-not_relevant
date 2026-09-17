<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_discovery_run_products', function (Blueprint $table): void {
            $table->unsignedInteger('still_needed')
                ->default(0)
                ->after('available_listings_before');
            $table->json('diagnostics')
                ->nullable()
                ->after('rejected_candidates');
        });
    }

    public function down(): void
    {
        Schema::table('listing_discovery_run_products', function (Blueprint $table): void {
            $table->dropColumn(['still_needed', 'diagnostics']);
        });
    }
};
