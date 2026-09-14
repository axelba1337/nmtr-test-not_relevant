<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_discovery_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('provider', 50);
            $table->string('model', 120);
            $table->string('region', 100);
            $table->unsignedInteger('products_checked')->default(0);
            $table->unsignedInteger('candidates_found')->default(0);
            $table->unsignedInteger('valid_candidates')->default(0);
            $table->unsignedInteger('rejected_candidates')->default(0);
            $table->unsignedInteger('provider_failures')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('listing_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_discovery_run_id')
                ->constrained('listing_discovery_runs')->cascadeOnDelete();
            $table->foreignId('target_product_id')->nullable()
                ->constrained('products')->nullOnDelete();
            $table->foreignId('listing_id')->nullable()
                ->constrained('listings')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('platform_slug', 64)->nullable()->index();
            $table->string('external_listing_id')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('price')->nullable();
            $table->char('currency', 3)->default('IDR');
            $table->string('condition', 32)->default('unknown');
            $table->string('seller_name')->nullable();
            $table->string('location')->nullable();
            $table->text('product_url')->nullable();
            $table->char('normalized_url_hash', 64)->nullable();
            $table->json('grounding_sources')->nullable();
            $table->json('raw_payload')->nullable();
            $table->string('validation_status', 32)->index();
            $table->string('review_status', 32)->index();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['listing_discovery_run_id', 'normalized_url_hash'],
                'listing_candidates_run_url_unique',
            );
        });

        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        // Queue tables are shared infrastructure and may predate this migration.
        // Keep them intact on rollback to avoid deleting unrelated queued work.
        Schema::dropIfExists('listing_candidates');
        Schema::dropIfExists('listing_discovery_runs');
    }
};
