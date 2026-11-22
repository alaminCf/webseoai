<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tracked Competitor Domains
        Schema::create('competitor_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('display_name', 255)->nullable();
            $table->string('category', 40)->default('direct'); // direct, organic_search, content, indirect
            $table->string('tier', 20)->default('primary'); // primary, secondary
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->string('discovery_source', 40)->default('manual');
            $table->unsignedTinyInteger('discovery_confidence')->nullable();
            $table->text('discovery_rationale')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'domain']);
            $table->index(['organization_id', 'website_id', 'is_active'], 'comp_dom_org_site_active_idx');
            $table->index(['website_id', 'category'], 'comp_dom_site_cat_idx');
        });

        // 2. Competitor Discovery Runs (requires manual confirmation before adding)
        Schema::create('competitor_discovery_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->string('search_engine', 30)->default('google');
            $table->string('industry_context', 255)->nullable();
            $table->string('provider', 50)->default('first_party_crawl');
            $table->text('methodology')->nullable();
            $table->string('status', 30)->default('completed'); // pending, running, completed, failed
            $table->json('candidates')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'created_at'], 'comp_disc_site_created_idx');
        });

        // 3. Domain Metric Snapshots (for both target website and tracked competitors)
        Schema::create('competitor_domain_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('competitor_domain_id')->nullable()->constrained('competitor_domains')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->boolean('is_target')->default(false);
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->unsignedBigInteger('organic_traffic_estimate')->nullable();
            $table->unsignedInteger('organic_keywords_count')->nullable();
            $table->decimal('traffic_value_estimate', 12, 2)->nullable();
            $table->unsignedInteger('referring_domains_count')->nullable();
            $table->unsignedBigInteger('backlinks_count')->nullable();
            $table->unsignedInteger('indexed_pages_count')->nullable();
            $table->unsignedTinyInteger('authority_score')->nullable();
            $table->string('authority_metric_label', 60)->nullable();
            $table->decimal('serp_visibility_score', 6, 2)->nullable();
            $table->json('ranking_distribution')->nullable();
            $table->json('top_keywords')->nullable();
            $table->json('top_pages')->nullable();
            $table->json('crawled_signals')->nullable();
            $table->string('data_source', 50)->default('first_party_crawl');
            $table->string('data_classification', 30)->default('first_party_verified');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['website_id', 'domain', 'captured_at'], 'comp_snap_site_dom_cap_idx');
            $table->index(['competitor_domain_id', 'captured_at'], 'comp_snap_comp_cap_idx');
        });

        // 4. Keyword Gap & Multi-Domain Keyword Comparison Records
        Schema::create('competitor_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('keyword', 255);
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedTinyInteger('keyword_difficulty')->nullable();
            $table->decimal('cpc', 8, 2)->nullable();
            $table->string('search_intent', 30)->nullable(); // informational, commercial, transactional, navigational
            $table->unsignedSmallInteger('target_position')->nullable();
            $table->string('target_url', 1024)->nullable();
            $table->json('competitor_positions')->nullable();
            $table->string('gap_type', 30)->default('missing'); // missing, weak, shared, strong, untapped
            $table->json('serp_features')->nullable();
            $table->decimal('opportunity_score', 5, 2)->nullable();
            $table->string('data_source', 50)->default('first_party_gsc');
            $table->string('data_classification', 30)->default('calculated');
            $table->timestamp('last_updated_at');
            $table->unsignedBigInteger('saved_recommendation_id')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'keyword', 'country', 'language'], 'comp_kw_unique');
            $table->index(['website_id', 'gap_type'], 'comp_kw_site_gap_idx');
            $table->index(['website_id', 'search_intent'], 'comp_kw_site_intent_idx');
            $table->index(['website_id', 'search_volume'], 'comp_kw_site_vol_idx');
        });

        // 5. Content Gap Opportunities
        Schema::create('competitor_content_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('topic', 255);
            $table->string('suggested_title', 255);
            $table->string('primary_keyword', 255);
            $table->json('secondary_keywords')->nullable();
            $table->string('search_intent', 30)->default('informational');
            $table->string('suggested_content_type', 60)->default('guide');
            $table->json('recommended_outline')->nullable();
            $table->json('competitor_reference_urls')->nullable();
            $table->string('target_coverage_status', 40)->default('unverified_crawl_needed');
            $table->text('rationale');
            $table->string('priority', 20)->default('medium'); // critical, high, medium, low
            $table->string('confidence', 20)->default('medium');
            $table->string('data_source', 50)->default('first_party_crawl');
            $table->string('status', 30)->default('open'); // open, sent_to_studio, saved_to_action_plan, dismissed
            $table->unsignedBigInteger('content_brief_id')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'priority', 'status'], 'comp_cgap_site_pri_stat_idx');
        });

        // 6. Backlink Gap Datasets
        Schema::create('competitor_backlinks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('competitor_domain_id')->nullable()->constrained('competitor_domains')->cascadeOnDelete();
            $table->string('target_domain', 255);
            $table->boolean('is_target_site')->default(false);
            $table->string('referring_domain', 255);
            $table->string('referring_url', 1024)->nullable();
            $table->string('destination_url', 1024)->nullable();
            $table->string('anchor_text', 512)->nullable();
            $table->string('link_type', 30)->default('dofollow'); // dofollow, nofollow, ugc, sponsored, unknown
            $table->unsignedTinyInteger('referring_authority')->nullable();
            $table->string('link_status', 20)->default('active'); // active, new, lost
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('data_source', 50);
            $table->timestamps();

            $table->index(['website_id', 'referring_domain'], 'comp_bl_site_ref_idx');
            $table->index(['website_id', 'is_target_site'], 'comp_bl_site_target_idx');
            $table->index(['website_id', 'link_type'], 'comp_bl_site_type_idx');
        });

        // 7. SERP Analysis Snapshots
        Schema::create('competitor_serp_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('keyword', 255);
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->string('search_engine', 30)->default('google');
            $table->string('device', 20)->default('desktop');
            $table->string('location', 120)->nullable();
            $table->boolean('is_monitored')->default(false);
            $table->string('search_intent', 30)->nullable();
            $table->json('serp_features')->nullable();
            $table->json('organic_results')->nullable();
            $table->unsignedSmallInteger('target_position')->nullable();
            $table->json('competitor_presence')->nullable();
            $table->json('content_patterns')->nullable();
            $table->string('data_source', 50);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['website_id', 'keyword', 'captured_at'], 'comp_serp_site_kw_cap_idx');
            $table->index(['website_id', 'is_monitored'], 'comp_serp_site_mon_idx');
        });

        // 8. AI Competitor Strategy Reports
        Schema::create('competitor_strategy_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('analysis_job_id')->nullable();
            $table->json('competitor_ids')->nullable();
            $table->text('executive_summary');
            $table->json('strength_analysis')->nullable();
            $table->json('weakness_analysis')->nullable();
            $table->json('keyword_opportunities')->nullable();
            $table->json('content_opportunities')->nullable();
            $table->json('backlink_opportunities')->nullable();
            $table->json('serp_opportunities')->nullable();
            $table->json('strategic_recommendations')->nullable();
            $table->string('provider_model', 80)->default('evidence-competitor-strategist-v1');
            $table->json('data_sources_used')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['website_id', 'generated_at'], 'comp_strat_site_gen_idx');
        });

        // 9. Competitor Monitoring Configurations & Alert Feed
        Schema::create('competitor_monitoring_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('frequency', 20)->default('weekly'); // daily, weekly, monthly, manual
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->boolean('notify_on_rank_change')->default(true);
            $table->boolean('notify_on_new_keywords')->default(true);
            $table->boolean('notify_on_backlink_change')->default(true);
            $table->unsignedTinyInteger('min_position_delta')->default(3);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->json('recent_alerts')->nullable();
            $table->timestamps();

            $table->unique('website_id');
        });

        // 10. Competitor Analysis Jobs
        Schema::create('competitor_analysis_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('job_type', 40)->default('full_analysis');
            $table->string('status', 30)->default('queued'); // queued, running, completed, failed
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->json('competitor_ids')->nullable();
            $table->json('parameters')->nullable();
            $table->json('result_summary')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'created_at'], 'comp_job_site_created_idx');
        });

        // 11. External Provider Usage & API Audit Logs
        Schema::create('competitor_provider_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->cascadeOnDelete();
            $table->string('provider', 50);
            $table->string('capability', 40);
            $table->string('endpoint', 255);
            $table->string('status', 20); // success, cached, failed, unconfigured
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'provider', 'created_at'], 'comp_log_org_prov_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitor_provider_logs');
        Schema::dropIfExists('competitor_analysis_jobs');
        Schema::dropIfExists('competitor_monitoring_configs');
        Schema::dropIfExists('competitor_strategy_reports');
        Schema::dropIfExists('competitor_serp_snapshots');
        Schema::dropIfExists('competitor_backlinks');
        Schema::dropIfExists('competitor_content_gaps');
        Schema::dropIfExists('competitor_keywords');
        Schema::dropIfExists('competitor_domain_snapshots');
        Schema::dropIfExists('competitor_discovery_runs');
        Schema::dropIfExists('competitor_domains');
    }
};
