<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            if (Schema::hasTable('ki_keywords')) {
                DB::statement('ALTER TABLE ki_keywords ALTER COLUMN ranking_potential TYPE VARCHAR(100)');
                DB::statement('ALTER TABLE ki_keywords ALTER COLUMN competition_level TYPE VARCHAR(40)');
                DB::statement('ALTER TABLE ki_keywords ALTER COLUMN opportunity_category TYPE VARCHAR(50)');
            }
            if (Schema::hasTable('ki_clusters')) {
                DB::statement('ALTER TABLE ki_clusters ALTER COLUMN name TYPE VARCHAR(255)');
                DB::statement('ALTER TABLE ki_clusters ALTER COLUMN suggested_pillar_page TYPE VARCHAR(500)');
            }
            return;
        }

        // SQLite / MySQL compatible schema change
        if (Schema::hasTable('ki_keywords')) {
            Schema::table('ki_keywords', function (Blueprint $table) {
                $table->string('ranking_potential', 100)->nullable()->change();
                $table->string('competition_level', 40)->nullable()->change();
                $table->string('opportunity_category', 50)->nullable()->change();
            });
        }

        if (Schema::hasTable('ki_clusters')) {
            Schema::table('ki_clusters', function (Blueprint $table) {
                $table->string('name', 255)->change();
                $table->string('suggested_pillar_page', 500)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Widening varchar columns is safely non-destructive; no shrink needed on rollback.
    }
};
