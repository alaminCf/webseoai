<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('competitor_discovery_runs', 'countries')) {
            Schema::table('competitor_discovery_runs', function (Blueprint $table) {
                $table->json('countries')->nullable()->after('country');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('competitor_discovery_runs', 'countries')) {
            Schema::table('competitor_discovery_runs', function (Blueprint $table) {
                $table->dropColumn('countries');
            });
        }
    }
};
