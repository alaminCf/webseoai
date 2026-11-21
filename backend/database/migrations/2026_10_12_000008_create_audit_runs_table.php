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
        Schema::create('audit_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('crawl_job_id')->constrained('crawl_jobs')->cascadeOnDelete();
            $table->unsignedSmallInteger('overall_score')->default(0);
            $table->unsignedSmallInteger('technical_score')->default(0);
            $table->unsignedSmallInteger('content_score')->default(0);
            $table->unsignedSmallInteger('link_score')->default(0);
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->unsignedInteger('info_count')->default(0);
            $table->json('summary_stats')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['website_id', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_runs');
    }
};
