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
        Schema::create('website_context_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->onDelete('cascade');
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->string('business_name')->nullable();
            $table->text('business_description')->nullable();
            $table->text('target_audience')->nullable();
            $table->text('products_and_services')->nullable();
            $table->json('target_countries')->nullable();
            $table->json('target_languages')->nullable();
            $table->text('brand_voice')->nullable();
            $table->json('business_goals')->nullable();
            $table->json('competitors')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique('website_id');
            $table->index('organization_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_context_profiles');
    }
};
