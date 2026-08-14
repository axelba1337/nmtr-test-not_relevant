<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('spec_source_name', 120)
                ->nullable()
                ->after('attributes');
            $table->text('spec_source_url')
                ->nullable()
                ->after('spec_source_name');
            $table->timestamp('spec_source_checked_at')
                ->nullable()
                ->after('spec_source_url');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'spec_source_name',
                'spec_source_url',
                'spec_source_checked_at',
            ]);
        });
    }
};
