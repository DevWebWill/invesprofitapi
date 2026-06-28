<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_zone_snapshots', function (Blueprint $table): void {
            if (!Schema::hasColumn('market_zone_snapshots', 'avg_monthly_rent')) {
                $table->decimal('avg_monthly_rent', 10, 2)->nullable()->after('avg_rent_price_sqm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('market_zone_snapshots', function (Blueprint $table): void {
            if (Schema::hasColumn('market_zone_snapshots', 'avg_monthly_rent')) {
                $table->dropColumn('avg_monthly_rent');
            }
        });
    }
};
