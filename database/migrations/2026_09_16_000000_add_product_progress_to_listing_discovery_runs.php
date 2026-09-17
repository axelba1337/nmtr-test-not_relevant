<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_discovery_runs', function (Blueprint $table): void {
            $table->unsignedInteger('products_targeted')
                ->default(0)
                ->after('region');
        });

        Schema::create('listing_discovery_run_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_discovery_run_id')
                ->constrained('listing_discovery_runs')
                ->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()
                ->constrained('products')
                ->nullOnDelete();
            $table->unsignedInteger('available_listings_before')->default(0);
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('valid_candidates')->default(0);
            $table->unsignedInteger('rejected_candidates')->default(0);
            $table->boolean('provider_failed')->default(false);
            $table->string('provider_error_code', 100)->nullable();
            $table->string('status', 32)->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['listing_discovery_run_id', 'product_id'],
                'listing_discovery_run_product_unique',
            );
            $table->index(
                ['product_id', 'finished_at'],
                'listing_discovery_product_finished_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_discovery_run_products');

        Schema::table('listing_discovery_runs', function (Blueprint $table): void {
            $table->dropColumn('products_targeted');
        });
    }
};
