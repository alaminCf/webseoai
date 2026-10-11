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
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->string('domain');
            $table->string('target_url', 1024);
            $table->string('platform', 30)->default('custom'); // wordpress, shopify, custom
            $table->string('timezone', 50)->default('UTC');
            $table->string('preferred_language', 10)->default('en');
            $table->string('target_country', 10)->default('US');
            
            // Statuses
            $table->string('connection_status', 30)->default('disconnected'); // connected, disconnected, pending, failed
            $table->string('verification_status', 30)->default('unverified'); // unverified, pending, verified, failed
            $table->string('verification_token', 64)->nullable();
            $table->string('verification_method', 30)->nullable(); // meta_tag, dns_txt, file_token
            $table->timestamp('verified_at')->nullable();

            // Tracking metrics
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_audited_at')->nullable();
            $table->unsignedSmallInteger('health_score')->default(0);

            $table->json('settings')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'domain']);
            $table->index(['organization_id', 'is_archived']);
            $table->index(['organization_id', 'connection_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
