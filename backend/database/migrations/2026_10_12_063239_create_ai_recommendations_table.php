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
        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->foreignId('audit_run_id')->nullable()->constrained('audit_runs')->onDelete('set null');
            $table->foreignId('audit_issue_id')->nullable()->constrained('audit_issues')->onDelete('set null');
            $table->foreignId('crawled_page_id')->nullable()->constrained('crawled_pages')->onDelete('set null');

            $table->string('title');
            $table->string('category')->default('technical'); // technical, content, links, architecture
            $table->string('affected_url');
            $table->json('evidence')->nullable();
            $table->text('recommended_action');
            $table->text('reasoning_summary');
            $table->text('expected_benefit'); // Estimated benefit, never a promise
            $table->string('confidence_level')->default('medium'); // high, medium, low
            $table->string('effort')->default('medium'); // low, medium, high
            $table->string('risk_level')->default('low'); // low, medium, high
            $table->string('required_permissions')->default('editor'); // editor, admin, etc.
            $table->text('verification_procedure');

            // Quantitative ICE scores (1 - 10)
            $table->unsignedTinyInteger('impact_score')->default(5);
            $table->unsignedTinyInteger('confidence_score')->default(5);
            $table->unsignedTinyInteger('effort_score')->default(5);
            $table->unsignedTinyInteger('risk_score')->default(2);
            $table->decimal('priority_score', 8, 2)->default(0); // (Impact * Confidence) / (Effort + Risk * 0.5)

            $table->string('status')->default('proposed'); // proposed, accepted, in_progress, applied, dismissed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index('priority_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
    }
};
