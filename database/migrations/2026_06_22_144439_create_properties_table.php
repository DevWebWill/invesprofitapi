<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->unique(); // prop-mad-1, etc
            $table->string('title');
            $table->string('property_type'); // piso, atico, duplex, casa_independiente, chalet_pareado, chalet_adosado, casa_rustica
            $table->enum('listing_mode', ['sale', 'rent']);
            $table->decimal('lat', 10, 6);
            $table->decimal('lng', 10, 6);
            $table->integer('price');
            $table->integer('monthly_rent')->nullable();
            $table->integer('bedrooms');
            $table->integer('bathrooms');
            $table->integer('area_m2');
            $table->string('city');
            $table->string('region_slug');
            $table->decimal('yield_gross', 4, 1);
            $table->decimal('yield_net', 4, 1);
            $table->integer('investment_score');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
