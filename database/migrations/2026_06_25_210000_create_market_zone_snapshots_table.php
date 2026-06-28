<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_zone_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_month');
            $table->string('zone_key', 220);
            $table->string('zone_slug', 220);
            $table->string('zone_name', 180);
            $table->enum('location_level', ['community', 'province', 'city', 'district', 'neighborhood']);

            $table->string('community', 140)->nullable();
            $table->string('province', 140)->nullable();
            $table->string('city', 140)->nullable();
            $table->string('district', 160)->nullable();
            $table->string('neighborhood', 180)->nullable();

            $table->enum('operation', ['all', 'sale', 'rent'])->default('all');
            $table->string('asset_type', 60)->default('all');

            $table->unsignedInteger('analyzed_properties_count')->default(0);
            $table->unsignedInteger('opportunities_count')->default(0);
            $table->decimal('avg_sale_price_sqm', 12, 2)->nullable();
            $table->decimal('avg_rent_price_sqm', 10, 2)->nullable();
            $table->decimal('estimated_yield', 6, 2)->nullable();
            $table->decimal('investment_score', 6, 2)->nullable();

            $table->decimal('sale_price_change_pct', 6, 2)->nullable();
            $table->decimal('rent_price_change_pct', 6, 2)->nullable();
            $table->decimal('opportunities_change_pct', 6, 2)->nullable();

            $table->enum('data_source', ['synthetic', 'real'])->default('synthetic');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(
                ['snapshot_month', 'zone_key', 'operation', 'asset_type'],
                'market_zone_snapshots_unique_month_zone_scope'
            );

            $table->index(['zone_key', 'snapshot_month']);
            $table->index(['community', 'province', 'city', 'district', 'neighborhood'], 'market_zone_snapshots_location_idx');
            $table->index(['operation', 'asset_type', 'snapshot_month'], 'market_zone_snapshots_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_zone_snapshots');
    }
};
