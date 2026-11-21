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
        Schema::create('crawl_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('queued'); // queued, crawling, completed, failed, cancelled
            $table->unsignedInteger('max_pages')->default(100);
            $table->unsignedInteger('max_depth')->default(3);
            $table->unsignedInteger('concurrency')->default(2);
            $table->unsignedInteger('pages_discovered')->default(0);
            $table->unsignedInteger('pages_crawled')->default(0);
            $table->unsignedInteger('pages_failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('config')->nullable();
            $table->json('execution_logs')->nullable();
            $table->timestamps();

            $table->index(['website_id', 'status']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crawl_jobs');
    }
};
