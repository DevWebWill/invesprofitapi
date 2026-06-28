<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->string('source')->nullable()->after('external_id');
            $table->string('detail_url', 2048)->nullable()->after('source');
            $table->string('source_url', 2048)->nullable()->after('detail_url');
            $table->text('description')->nullable()->after('title');
            $table->json('images')->nullable()->after('investment_score');
            $table->json('source_payload')->nullable()->after('images');
            $table->timestamp('scraped_at')->nullable()->after('source_payload');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn([
                'source',
                'detail_url',
                'source_url',
                'description',
                'images',
                'source_payload',
                'scraped_at',
            ]);
        });
    }
};
