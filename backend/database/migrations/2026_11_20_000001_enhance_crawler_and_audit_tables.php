<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->text('final_url')->nullable()->after('url');
            $table->unsignedInteger('html_size_bytes')->default(0)->after('load_time_ms');
            $table->unsignedInteger('ttfb_ms')->nullable()->after('load_time_ms');
            $table->char('content_hash', 64)->nullable()->after('word_count');
            $table->boolean('is_js_rendered')->default(false)->after('in_sitemap');
            $table->boolean('is_spa_shell')->default(false)->after('is_js_rendered');
            $table->boolean('is_blocked_by_waf')->default(false)->after('is_spa_shell');
            $table->string('viewport', 255)->nullable()->after('canonical_url');
            $table->string('html_lang', 32)->nullable()->after('viewport');
            $table->string('charset', 32)->nullable()->after('html_lang');
            $table->text('favicon_url')->nullable()->after('charset');
            $table->json('open_graph')->nullable()->after('favicon_url');
            $table->json('twitter_card')->nullable()->after('open_graph');
            $table->json('structured_data')->nullable()->after('twitter_card');
            $table->json('hreflang_tags')->nullable()->after('structured_data');
            $table->json('security_headers')->nullable()->after('raw_headers');
            $table->json('mixed_content_urls')->nullable()->after('security_headers');
            $table->unsignedSmallInteger('images_without_dimensions')->default(0)->after('images_missing_alt');
            $table->json('images_missing_alt_samples')->nullable()->after('images_without_dimensions');
            $table->string('meta_refresh', 255)->nullable()->after('x_robots_tag');

            $table->index(['crawl_job_id', 'content_hash']);
        });

        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->json('site_checks')->nullable()->after('config');
        });

        Schema::table('audit_runs', function (Blueprint $table) {
            $table->unsignedTinyInteger('indexing_score')->default(100)->after('link_score');
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropIndex(['crawl_job_id', 'content_hash']);
            $table->dropColumn([
                'final_url',
                'html_size_bytes',
                'ttfb_ms',
                'content_hash',
                'is_js_rendered',
                'is_spa_shell',
                'is_blocked_by_waf',
                'viewport',
                'html_lang',
                'charset',
                'favicon_url',
                'open_graph',
                'twitter_card',
                'structured_data',
                'hreflang_tags',
                'security_headers',
                'mixed_content_urls',
                'images_without_dimensions',
                'images_missing_alt_samples',
                'meta_refresh',
            ]);
        });

        Schema::table('crawl_jobs', function (Blueprint $table) {
            $table->dropColumn('site_checks');
        });

        Schema::table('audit_runs', function (Blueprint $table) {
            $table->dropColumn('indexing_score');
        });
    }
};
