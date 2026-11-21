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
        Schema::create('keyword_research_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->string('keyword', 255);
            $table->string('country', 10)->default('US');
            $table->string('language', 10)->default('en');
            $table->unsignedInteger('search_volume')->nullable();
            $table->decimal('difficulty', 5, 2)->nullable(); // 0.00 to 100.00
            $table->decimal('cpc', 8, 2)->nullable(); // Cost per click in USD
            $table->string('intent', 30)->nullable(); // informational, commercial, transactional, navigational
            $table->string('competition_level', 20)->nullable(); // LOW, MEDIUM, HIGH
            $table->string('provider', 50); // sandbox, dataforseo, semrush
            $table->json('provenance'); // fetched_at, source, is_stale, raw_data_id
            $table->json('related_clusters')->nullable(); // cluster suggestions
            $table->timestamps();

            $table->index(['organization_id', 'website_id']);
            $table->index(['keyword', 'country', 'language']);
            $table->index(['provider']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keyword_research_records');
    }
};
