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
        Schema::create('crawled_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_job_id')->constrained('crawl_jobs')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 64);
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('load_time_ms')->nullable();
            $table->string('content_type', 100)->nullable();
            $table->text('page_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('canonical_url')->nullable();
            $table->json('h1_tags')->nullable();
            $table->json('headings_tree')->nullable();
            $table->string('meta_robots', 100)->nullable();
            $table->string('x_robots_tag', 100)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('in_sitemap')->default(false);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedSmallInteger('images_count')->default(0);
            $table->unsignedSmallInteger('images_missing_alt')->default(0);
            $table->json('internal_links')->nullable();
            $table->json('external_links')->nullable();
            $table->unsignedInteger('in_degree')->default(0);
            $table->text('redirect_url')->nullable();
            $table->json('redirect_hops')->nullable();
            $table->json('raw_headers')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['crawl_job_id', 'url_hash']);
            $table->index(['website_id', 'url_hash']);
            $table->index(['crawl_job_id', 'status_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawled_pages');
    }
};
