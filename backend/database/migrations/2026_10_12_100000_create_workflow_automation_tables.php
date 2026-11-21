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
        // 1. Automation Rules (Autopilot policies and trigger configs)
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            
            // Triggers: schedule, crawl_completed, issue_detected, performance_threshold, content_approved, action_failed
            $table->string('trigger_type', 50);
            $table->json('trigger_config')->nullable(); // e.g. {"schedule": "weekly", "cron": "0 0 * * 1", "min_impact": 70}

            // Target Actions: full_audit_and_recommend, auto_stage_metadata_fix, publish_approved_content, sync_gsc_and_report, recover_failed_execution
            $table->string('action_type', 50);
            $table->json('action_config')->nullable(); // e.g. {"target": "missing_meta_description", "model": "gemini-2.5-flash"}

            // Policy & Safety Gates
            $table->boolean('is_write_capable')->default(false); // If true, touches remote website / CMS
            $table->boolean('require_human_approval')->default(true); // AI recommends/stages, but never self-approves writes unless explicitly permitted
            $table->boolean('dry_run_mode')->default(false); // Simulates actions without committing live mutations
            $table->string('status', 30)->default('active'); // active, paused, disabled

            // Budget and rate limits
            $table->unsignedInteger('max_runs_per_day')->default(10);
            $table->decimal('monthly_cost_budget', 8, 2)->default(50.00); // USD budget limit for AI/worker
            $table->decimal('current_month_cost', 8, 2)->default(0.00);

            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['organization_id', 'trigger_type']);
        });

        // 2. Workflow Execution Runs (Durable runs with step-level results)
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('automation_rule_id')->nullable()->constrained('automation_rules')->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('run_uuid', 64)->unique();
            $table->string('idempotency_key', 64)->unique();
            $table->string('trigger_source', 50); // schedule, webhook, event, manual_test
            $table->unsignedInteger('rule_version')->default(1);

            // States: queued, running, waiting_for_approval, succeeded, failed, cancelled, partially_failed
            $table->string('status', 30)->default('queued');

            $table->json('trigger_payload')->nullable(); // Event context (e.g. crawl_job_id, audit_issue_id)
            $table->json('step_results')->nullable(); // Detailed step execution log: [{"step": "crawl", "status": "completed", ...}]
            
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);
            $table->text('error_details')->nullable();
            $table->decimal('cost_incurred', 8, 4)->default(0.0000); // Tracked USD cost
            $table->unsignedInteger('tokens_consumed')->default(0);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['automation_rule_id', 'status']);
            $table->index(['created_at']);
        });

        // 3. Approval Queue Items (Action requests paused waiting for human signature)
        Schema::create('workflow_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->foreignId('cms_change_request_id')->nullable()->constrained('cms_change_requests')->nullOnDelete();
            
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('risk_level', 20)->default('medium'); // low, medium, high
            $table->json('proposed_diff')->nullable();

            $table->string('status', 30)->default('pending'); // pending, approved, rejected, revoked, expired
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['workflow_run_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_approvals');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('automation_rules');
    }
};
