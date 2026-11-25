<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('keyword_projects')) {
            Schema::create('keyword_projects', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 180);
                $table->string('target_country', 4)->default('US');
                $table->string('target_language', 10)->default('en');
                $table->string('search_engine', 30)->default('google');
                $table->string('search_location', 150)->nullable();
                $table->string('device', 20)->default('desktop');
                $table->string('search_network', 30)->default('google_search');
                $table->string('business_name', 180)->nullable();
                $table->string('business_category', 180)->nullable();
                $table->json('scoring_weights')->nullable();
                $table->string('status', 20)->default('active');
                $table->timestamps();

                $table->index(['organization_id', 'website_id']);
            });
        }

        if (!Schema::hasTable('ki_seed_keywords')) {
            Schema::create('ki_seed_keywords', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('project_id')->nullable()->constrained('keyword_projects')->nullOnDelete();
                $table->string('seed_text', 255);
                $table->string('seed_type', 30)->default('keyword'); // keyword, url, business, category, gsc, competitor
                $table->string('country', 4)->default('US');
                $table->string('language', 10)->default('en');
                $table->json('sources_used')->nullable();
                $table->unsignedInteger('discovered_count')->default(0);
                $table->timestamp('last_researched_at')->nullable();
                $table->timestamps();

                $table->index(['website_id', 'created_at']);
                $table->index(['organization_id', 'website_id']);
            });
        }

        if (!Schema::hasTable('ki_keywords')) {
            Schema::create('ki_keywords', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('project_id')->nullable()->constrained('keyword_projects')->nullOnDelete();
                $table->foreignId('seed_keyword_id')->nullable()->constrained('ki_seed_keywords')->nullOnDelete();
                $table->string('keyword', 255);
                $table->string('normalized_keyword', 255);
                $table->string('country', 4)->default('US');
                $table->string('language', 10)->default('en');
                $table->string('search_engine', 30)->default('google');
                $table->string('device', 20)->default('desktop');
                $table->string('location', 150)->nullable();
                $table->string('discovery_source', 50)->default('suggestions');
                $table->string('question_category', 20)->nullable();
                $table->boolean('is_long_tail')->default(false);
                $table->unsignedSmallInteger('word_count')->default(1);

                // Strictly provider-supplied metrics (null = Data Unavailable — never invented)
                $table->unsignedInteger('search_volume')->nullable();
                $table->decimal('keyword_difficulty', 5, 2)->nullable();
                $table->decimal('cpc', 8, 2)->nullable();
                $table->decimal('paid_competition', 5, 4)->nullable();
                $table->string('competition_level', 20)->nullable();

                // Search intent + provenance
                $table->string('search_intent', 30)->nullable();
                $table->string('intent_source', 30)->nullable(); // provider, rule_based, ai_inferred
                $table->decimal('intent_confidence', 4, 2)->nullable();

                // Trends & SERP
                $table->json('trend_data')->nullable();
                $table->string('trend_direction', 20)->nullable(); // rising, declining, stable, seasonal, unavailable
                $table->json('serp_features')->nullable();
                $table->json('serp_top_urls')->nullable();

                // First-party GSC & Rank Checker signals
                $table->decimal('current_position', 6, 2)->nullable();
                $table->decimal('previous_position', 6, 2)->nullable();
                $table->text('ranking_url')->nullable();
                $table->unsignedInteger('gsc_clicks')->nullable();
                $table->unsignedInteger('gsc_impressions')->nullable();
                $table->decimal('gsc_ctr', 6, 2)->nullable();
                $table->unsignedSmallInteger('competitor_coverage_count')->default(0);

                // Transparent Opportunity Scoring
                $table->decimal('opportunity_score', 5, 2)->nullable();
                $table->decimal('relevance_score', 5, 2)->nullable();
                $table->string('ranking_potential', 30)->nullable();
                $table->string('opportunity_category', 30)->nullable();
                $table->string('recommended_priority', 10)->nullable();
                $table->text('opportunity_reason')->nullable();
                $table->string('data_confidence', 20)->default('low');

                // Attribution & Workflow Links
                $table->string('data_source', 60)->default('google_autocomplete');
                $table->string('metric_classification', 40)->default('calculated');
                $table->boolean('is_saved')->default(false);
                $table->boolean('is_monitored')->default(false);
                $table->foreignId('saved_recommendation_id')->nullable()->constrained('ai_recommendations')->nullOnDelete();
                $table->foreignId('saved_brief_id')->nullable()->constrained('content_briefs')->nullOnDelete();
                $table->timestamp('last_updated_at')->nullable();
                $table->timestamps();

                $table->unique(['website_id', 'normalized_keyword', 'country', 'language'], 'ki_kw_unique_market');
                $table->index(['organization_id', 'website_id']);
                $table->index(['website_id', 'search_intent']);
                $table->index(['website_id', 'opportunity_category']);
                $table->index(['website_id', 'is_saved']);
                $table->index(['website_id', 'is_long_tail']);
                $table->index(['website_id', 'question_category']);
                $table->index(['website_id', 'opportunity_score']);
            });
        }

        if (!Schema::hasTable('ki_keyword_history')) {
            Schema::create('ki_keyword_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('keyword_id')->constrained('ki_keywords')->cascadeOnDelete();
                $table->unsignedInteger('search_volume')->nullable();
                $table->decimal('keyword_difficulty', 5, 2)->nullable();
                $table->decimal('cpc', 8, 2)->nullable();
                $table->decimal('position', 6, 2)->nullable();
                $table->text('ranking_url')->nullable();
                $table->unsignedInteger('gsc_clicks')->nullable();
                $table->unsignedInteger('gsc_impressions')->nullable();
                $table->string('data_source', 60)->default('discovery');
                $table->timestamp('captured_at');
                $table->timestamps();

                $table->index(['keyword_id', 'captured_at']);
                $table->index(['website_id', 'captured_at']);
            });
        }

        if (!Schema::hasTable('ki_clusters')) {
            Schema::create('ki_clusters', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('project_id')->nullable()->constrained('keyword_projects')->nullOnDelete();
                $table->string('name', 180);
                $table->string('primary_keyword', 255);
                $table->string('clustering_method', 30)->default('semantic'); // semantic, serp, manual
                $table->string('search_intent', 30)->nullable();
                $table->unsignedInteger('keyword_count')->default(0);
                $table->unsignedInteger('combined_search_volume')->nullable();
                $table->decimal('avg_difficulty', 5, 2)->nullable();
                $table->decimal('opportunity_score', 5, 2)->default(0);
                $table->string('suggested_pillar_page', 255)->nullable();
                $table->json('suggested_supporting_content')->nullable();
                $table->boolean('is_saved')->default(false);
                $table->foreignId('sent_to_content_studio_brief_id')->nullable()->constrained('content_briefs')->nullOnDelete();
                $table->timestamps();

                $table->index(['organization_id', 'website_id']);
                $table->index(['website_id', 'search_intent']);
            });
        }

        if (!Schema::hasTable('ki_cluster_memberships')) {
            Schema::create('ki_cluster_memberships', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cluster_id')->constrained('ki_clusters')->cascadeOnDelete();
                $table->foreignId('keyword_id')->constrained('ki_keywords')->cascadeOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->decimal('similarity_score', 4, 2)->default(1.00);
                $table->timestamps();

                $table->unique(['cluster_id', 'keyword_id']);
            });
        }

        if (!Schema::hasTable('ki_cannibalization_findings')) {
            Schema::create('ki_cannibalization_findings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->string('keyword', 255);
                $table->json('competing_urls');
                $table->unsignedInteger('total_impressions')->default(0);
                $table->unsignedInteger('total_clicks')->default(0);
                $table->decimal('best_position', 6, 2)->nullable();
                $table->decimal('position_spread', 6, 2)->default(0);
                $table->string('confidence', 20)->default('medium'); // high, medium, low
                $table->text('evidence_summary');
                $table->text('suggested_resolution');
                $table->string('status', 20)->default('open'); // open, acknowledged, resolved, dismissed
                $table->timestamp('detected_at');
                $table->timestamps();

                $table->index(['website_id', 'status']);
                $table->index(['organization_id', 'website_id']);
            });
        }

        if (!Schema::hasTable('ki_automation_workflows')) {
            Schema::create('ki_automation_workflows', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 180);
                $table->string('status', 20)->default('active'); // active, paused
                $table->json('seed_keywords');
                $table->string('business_category', 180)->nullable();
                $table->string('target_country', 4)->default('US');
                $table->string('target_language', 10)->default('en');
                $table->json('discovery_sources')->nullable();
                $table->string('schedule_frequency', 20)->default('weekly'); // daily, weekly, monthly, custom
                $table->unsignedInteger('custom_interval_hours')->nullable();
                $table->boolean('auto_cluster')->default(true);
                $table->boolean('auto_monitor_rank')->default(false);
                $table->boolean('auto_content_recommendations')->default(true);
                $table->boolean('notify_on_opportunities')->default(true);
                $table->unsignedSmallInteger('min_opportunity_score')->default(65);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable()->index();
                $table->unsignedInteger('total_keywords_discovered')->default(0);
                $table->unsignedInteger('total_opportunities_found')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'website_id']);
                $table->index(['status', 'next_run_at']);
            });
        }

        if (!Schema::hasTable('ki_automation_runs')) {
            Schema::create('ki_automation_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('workflow_id')->constrained('ki_automation_workflows')->cascadeOnDelete();
                $table->string('trigger_type', 20)->default('manual'); // scheduled, manual
                $table->string('status', 20)->default('running'); // running, completed, failed
                $table->unsignedInteger('keywords_discovered')->default(0);
                $table->unsignedInteger('new_keywords')->default(0);
                $table->unsignedInteger('clusters_updated')->default(0);
                $table->unsignedInteger('opportunities_found')->default(0);
                $table->unsignedInteger('alerts_created')->default(0);
                $table->unsignedInteger('content_recommendations_created')->default(0);
                $table->unsignedInteger('duration_ms')->default(0);
                $table->json('summary')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['workflow_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('ki_alerts')) {
            Schema::create('ki_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('workflow_id')->nullable()->constrained('ki_automation_workflows')->nullOnDelete();
                $table->foreignId('keyword_id')->nullable()->constrained('ki_keywords')->nullOnDelete();
                $table->string('alert_type', 40); // new_keywords, high_opportunity, competitor_gain, trend_shift, content_opportunity, cannibalization
                $table->string('severity', 20)->default('info'); // info, high, critical
                $table->string('title', 255);
                $table->text('message');
                $table->json('evidence')->nullable();
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['website_id', 'is_read']);
                $table->index(['organization_id', 'website_id']);
            });
        }

        if (!Schema::hasTable('ki_provider_usage_logs')) {
            Schema::create('ki_provider_usage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('provider', 50);
                $table->string('operation', 60);
                $table->string('query_summary', 255)->nullable();
                $table->unsignedInteger('results_count')->default(0);
                $table->unsignedInteger('credits_used')->default(0);
                $table->decimal('estimated_cost_usd', 10, 5)->default(0);
                $table->boolean('cache_hit')->default(false);
                $table->string('status', 20)->default('success');
                $table->unsignedInteger('duration_ms')->default(0);
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'created_at']);
                $table->index(['provider', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ki_provider_usage_logs');
        Schema::dropIfExists('ki_alerts');
        Schema::dropIfExists('ki_automation_runs');
        Schema::dropIfExists('ki_automation_workflows');
        Schema::dropIfExists('ki_cannibalization_findings');
        Schema::dropIfExists('ki_cluster_memberships');
        Schema::dropIfExists('ki_clusters');
        Schema::dropIfExists('ki_keyword_history');
        Schema::dropIfExists('ki_keywords');
        Schema::dropIfExists('ki_seed_keywords');
        Schema::dropIfExists('keyword_projects');
    }
};
