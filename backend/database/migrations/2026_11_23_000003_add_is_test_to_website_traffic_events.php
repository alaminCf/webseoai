<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('website_traffic_events', 'is_test')) {
            Schema::table('website_traffic_events', function (Blueprint $table) {
                $table->boolean('is_test')->default(false)->after('event_type');
                $table->index(['website_id', 'is_test', 'occurred_at'], 'traffic_site_test_time_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('website_traffic_events', 'is_test')) {
            Schema::table('website_traffic_events', function (Blueprint $table) {
                $table->dropIndex('traffic_site_test_time_idx');
                $table->dropColumn('is_test');
            });
        }
    }
};
