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
        Schema::create('website_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('provider', 50); // wordpress_rest, shopify_graphql, gsc_oauth
            $table->text('encrypted_credentials'); // encrypted via Crypt::encryptString()
            $table->string('status', 30)->default('disconnected'); // connected, disconnected, pending, failed
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('metadata')->nullable(); // non-secret metadata (e.g. username, site title, scope)
            $table->timestamps();

            $table->unique(['website_id', 'provider']);
            $table->index(['organization_id', 'provider']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_integrations');
    }
};
