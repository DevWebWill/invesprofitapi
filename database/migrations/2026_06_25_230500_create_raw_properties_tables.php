<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_sale_properties', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->default('fotocasa');
            $table->string('external_id');
            $table->enum('listing_mode', ['sale']);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('property_type')->nullable();
            $table->string('location')->nullable();
            $table->string('city')->nullable();
            $table->string('region_slug')->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->string('price_text')->nullable();
            $table->integer('price_value')->nullable();
            $table->integer('price')->nullable();
            $table->integer('monthly_rent')->nullable();
            $table->integer('bedrooms')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->integer('area_m2')->nullable();
            $table->string('detail_url')->nullable();
            $table->string('source_url')->nullable();
            $table->json('images')->nullable();
            $table->json('downloaded_images')->nullable();
            $table->json('source_payload')->nullable();
            $table->json('raw_payload');
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('city');
            $table->index('region_slug');
        });

        Schema::create('raw_rent_properties', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->default('fotocasa');
            $table->string('external_id');
            $table->enum('listing_mode', ['rent']);
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('property_type')->nullable();
            $table->string('location')->nullable();
            $table->string('city')->nullable();
            $table->string('region_slug')->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->string('price_text')->nullable();
            $table->integer('price_value')->nullable();
            $table->integer('price')->nullable();
            $table->integer('monthly_rent')->nullable();
            $table->integer('bedrooms')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->integer('area_m2')->nullable();
            $table->string('detail_url')->nullable();
            $table->string('source_url')->nullable();
            $table->json('images')->nullable();
            $table->json('downloaded_images')->nullable();
            $table->json('source_payload')->nullable();
            $table->json('raw_payload');
            $table->timestamp('scraped_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index('city');
            $table->index('region_slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_rent_properties');
        Schema::dropIfExists('raw_sale_properties');
    }
};
