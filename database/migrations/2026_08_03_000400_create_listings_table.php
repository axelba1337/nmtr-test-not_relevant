<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('external_listing_id')->nullable();
            $table->char('source_fingerprint', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price');
            $table->char('currency', 3)->default('IDR');
            $table->string('condition', 32)->default('unknown');
            $table->string('condition_source', 32)
                ->default('seller_description');
            $table->string('seller_name')->nullable();
            $table->string('seller_type', 32)->default('unknown');
            $table->string('location')->nullable();
            $table->text('product_url');
            $table->text('image_url')->nullable();
            $table->string('stock_status', 32)->default('unknown');
            $table->string('price_type', 32)->default('full_price');
            $table->unsignedBigInteger('shipping_cost')->nullable();
            $table->unsignedBigInteger('service_fee')->nullable();
            $table->unsignedBigInteger('inspection_cost')->nullable();
            $table->unsignedBigInteger('repair_estimate')->nullable();
            $table->string('warranty')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('source_published_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['platform_id', 'external_listing_id'],
                'listing_platform_external_unique',
            );
            $table->index(
                [
                    'product_id',
                    'condition',
                    'stock_status',
                    'is_active',
                ],
                'listing_comparable_index',
            );
            $table->index(
                ['seller_type', 'location'],
                'listing_seller_location_index',
            );
            $table->index(['price', 'currency']);
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
