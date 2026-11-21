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
        // 1. Client Workspaces (Agency Client Accounts)
        Schema::create('client_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('client_email')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'name']);
        });

        // 2. Link websites to client workspace
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('client_workspace_id')->nullable()->after('organization_id')
                ->constrained('client_workspaces')->nullOnDelete();
        });

        // 3. Website-Level Granular Permissions
        Schema::create('website_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_edit')->default(false);
            $table->boolean('can_publish')->default(false);
            $table->boolean('can_approve')->default(false);
            $table->timestamps();

            $table->unique(['website_id', 'user_id']);
        });

        // 4. Subscriptions table
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('plan_id')->default('starter');
            $table->string('status')->default('active'); // active, trialing, past_due, canceled, incomplete
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('card_brand')->nullable();
            $table->string('card_last_four')->nullable();
            $table->json('extra_data')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        // 5. Billing Webhook Events (strictly idempotent)
        Schema::create('billing_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('provider')->default('stripe');
            $table->string('event_type');
            $table->json('payload');
            $table->string('status')->default('pending'); // pending, processed, failed, ignored
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['provider', 'event_type']);
        });

        // 6. Client Approval Requests (Agency to Client sign-off)
        Schema::create('client_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('client_workspace_id')->nullable()->constrained('client_workspaces')->nullOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->string('approver_email');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('request_type')->default('cms_change'); // cms_change, seo_strategy, white_label_report
            $table->string('status')->default('pending'); // pending, approved, rejected, expired
            $table->string('token', 64)->unique();
            $table->json('metadata')->nullable();
            $table->text('client_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['website_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_approval_requests');
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('website_permissions');
        
        if (Schema::hasColumn('websites', 'client_workspace_id')) {
            Schema::table('websites', function (Blueprint $table) {
                $table->dropForeign(['client_workspace_id']);
                $table->dropColumn('client_workspace_id');
            });
        }
        
        Schema::dropIfExists('client_workspaces');
    }
};
