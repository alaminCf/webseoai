<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('websites', 'tracking_token')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->string('tracking_token', 64)->nullable()->unique()->after('verification_token');
            });
        }

        if (!Schema::hasTable('website_traffic_events')) {
            Schema::create('website_traffic_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->string('visitor_hash', 64);
                $table->string('session_id', 64)->nullable();
                $table->string('event_type', 20)->default('pageview'); // pageview, heartbeat
                $table->string('path', 512)->default('/');
                $table->string('page_title', 255)->nullable();
                $table->string('referrer_domain', 255)->nullable();
                $table->string('referrer_url', 1024)->nullable();
                $table->string('utm_source', 120)->nullable();
                $table->string('utm_medium', 120)->nullable();
                $table->string('utm_campaign', 120)->nullable();
                $table->string('country_code', 4)->nullable();
                $table->string('country_name', 100)->nullable();
                $table->string('region', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('device_type', 20)->default('desktop');
                $table->string('browser', 40)->nullable();
                $table->string('os', 40)->nullable();
                $table->timestamp('occurred_at');
                $table->timestamp('created_at')->nullable();

                $table->index(['website_id', 'occurred_at'], 'traffic_site_time_idx');
                $table->index(['website_id', 'country_code', 'occurred_at'], 'traffic_site_country_time_idx');
                $table->index(['website_id', 'visitor_hash', 'occurred_at'], 'traffic_site_visitor_time_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_traffic_events');

        if (Schema::hasColumn('websites', 'tracking_token')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropUnique(['tracking_token']);
                $table->dropColumn('tracking_token');
            });
        }
    }
};
