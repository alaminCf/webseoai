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
        Schema::create('cms_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('cms_provider', 50); // wordpress_rest, shopify_graphql, custom
            $table->string('target_type', 50); // page, post, product, article
            $table->string('target_identifier', 500); // remote ID or URL
            $table->string('snapshot_type', 30)->default('pre_change'); // pre_change, post_change, manual_backup, rollback_point
            $table->json('state_payload'); // Full snapshot data (title, meta, content, author, status)
            $table->string('checksum', 64); // SHA-256 hash of normalized payload
            $table->string('remote_modified_at')->nullable(); // Remote CMS timestamp for conflict detection
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['website_id', 'target_identifier']);
            $table->index(['organization_id', 'website_id']);
            $table->index(['created_at']);
        });

        Schema::create('cms_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Creator/Requester
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // Reviewer
            $table->string('idempotency_key', 64)->unique();
            $table->string('cms_provider', 50); // wordpress_rest, shopify_graphql, custom
            $table->string('action_type', 50); // update_metadata, update_content, create_draft, restore_snapshot
            $table->string('target_type', 50); // page, post, product, article
            $table->string('target_identifier', 500); // remote ID or URL
            $table->string('target_url', 2048)->nullable(); // canonical URL of affected page
            $table->json('proposed_payload'); // Changes to apply
            $table->string('status', 30)->default('pending_approval'); // pending_approval, approved, rejected, executing, verified, failed, rolled_back, uncertain
            $table->string('risk_level', 20)->default('medium'); // low, medium, high
            $table->text('rejection_reason')->nullable();
            $table->foreignId('pre_change_snapshot_id')->nullable()->constrained('cms_snapshots')->nullOnDelete();
            $table->foreignId('post_change_snapshot_id')->nullable()->constrained('cms_snapshots')->nullOnDelete();
            $table->json('diff_summary')->nullable(); // Computed field-by-field before/after diff
            $table->text('execution_error')->nullable();
            $table->json('verification_details')->nullable(); // Verification results (read-back values, match booleans)
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['website_id', 'action_type']);
            $table->index(['organization_id', 'website_id']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cms_change_requests');
        Schema::dropIfExists('cms_snapshots');
    }
};
