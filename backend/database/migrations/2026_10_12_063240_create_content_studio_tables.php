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
        Schema::create('content_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->string('target_keyword');
            $table->json('secondary_keywords')->nullable();
            $table->string('search_intent')->default('informational'); // informational, commercial, transactional, navigational
            $table->string('target_audience')->nullable();
            $table->string('content_type')->default('article'); // article, guide, landing_page, faq
            $table->string('suggested_title');
            $table->string('suggested_meta_description');
            $table->unsignedInteger('word_count_target')->default(1200);
            $table->json('outline')->nullable(); // Sections with headings and key points
            $table->json('internal_link_suggestions')->nullable();
            $table->json('faq_items')->nullable();

            $table->string('status')->default('draft'); // draft, in_review, approved, archived
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });

        Schema::create('content_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->foreignId('content_brief_id')->nullable()->constrained('content_briefs')->onDelete('set null');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');

            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('meta_description')->nullable();
            $table->string('canonical_url')->nullable();
            $table->longText('content_body');
            $table->unsignedInteger('word_count')->default(0);
            $table->string('search_intent')->default('informational');

            // Quality & Workflow Controls
            $table->unsignedTinyInteger('quality_score')->default(80);
            $table->json('quality_notes')->nullable(); // Flags, review comments, factual checks
            $table->string('status')->default('draft'); // draft, in_review, approved, ready_for_publish, rejected
            $table->unsignedInteger('version')->default(1);

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['website_id', 'status']);
        });

        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_article_id')->constrained('content_articles')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('content_body');
            $table->string('change_summary')->nullable();
            $table->timestamps();

            $table->index(['content_article_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
        Schema::dropIfExists('content_articles');
        Schema::dropIfExists('content_briefs');
    }
};
