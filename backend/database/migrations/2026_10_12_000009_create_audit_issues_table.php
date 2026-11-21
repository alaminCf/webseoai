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
        Schema::create('audit_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_run_id')->constrained('audit_runs')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('crawled_page_id')->nullable()->constrained('crawled_pages')->cascadeOnDelete();
            $table->string('issue_type', 100);
            $table->string('category', 50)->default('technical'); // technical, content, links, indexing
            $table->string('severity', 20)->default('warning'); // critical, warning, info
            $table->text('affected_url');
            $table->json('evidence')->nullable();
            $table->text('explanation');
            $table->text('recommended_fix');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('current_status', 30)->default('open'); // open, resolved, ignored
            $table->string('verification_status', 30)->default('unverified'); // unverified, verified
            $table->boolean('is_fully_determined')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['audit_run_id', 'severity']);
            $table->index(['website_id', 'issue_type']);
            $table->index(['website_id', 'current_status']);
            $table->index(['category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_issues');
    }
};
