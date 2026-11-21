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
        Schema::create('search_console_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('property_id', 255);
            $table->string('sync_type', 50)->default('analytics'); // analytics, inspection
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('rows_synced')->default(0);
            $table->string('status', 30)->default('pending'); // pending, running, success, partial, failed
            $table->text('error_message')->nullable();
            $table->json('metrics_summary')->nullable(); // total_clicks, total_impressions, avg_ctr, avg_position
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['organization_id', 'website_id']);
            $table->index(['created_at']);
        });

        Schema::create('search_performance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('property_id', 255);
            $table->date('date');
            $table->text('page'); // Target canonical / crawled URL
            $table->string('query', 500)->nullable(); // Search term
            $table->string('country', 10)->nullable(); // ISO 3166-1 alpha-3 or alpha-2
            $table->string('device', 30)->nullable(); // DESKTOP, MOBILE, TABLET
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('impressions')->default(0);
            $table->decimal('ctr', 6, 4)->default(0.0000); // 0.0520 = 5.2%
            $table->decimal('position', 6, 2)->default(0.00); // 1.00 = rank 1
            $table->timestamps();

            $table->index(['website_id', 'date']);
            $table->index(['website_id', 'query']);
            $table->index(['website_id', 'device']);
            $table->index(['organization_id', 'website_id']);
        });

        Schema::create('search_opportunities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('type', 50); // low_ctr, click_decay, rising_query, keyword_gap
            $table->string('target_type', 30); // page, query
            $table->text('target_identifier'); // URL or query string
            $table->string('headline', 255);
            $table->text('reasoning');
            $table->text('recommended_action');
            $table->unsignedSmallInteger('impact_score')->default(50); // 1-100
            $table->json('metrics_snapshot'); // current vs prior period, clicks, impressions, ctr, position
            $table->string('status', 30)->default('detected'); // detected, acknowledged, dismissed, resolved
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['website_id', 'type']);
            $table->index(['organization_id', 'website_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_opportunities');
        Schema::dropIfExists('search_performance_records');
        Schema::dropIfExists('search_console_sync_logs');
    }
};
