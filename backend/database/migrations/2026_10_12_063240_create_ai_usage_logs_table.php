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
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('website_id')->nullable()->constrained('websites')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');

            $table->string('provider'); // gemini, openai, deterministic_studio
            $table->string('model');
            $table->string('feature'); // brief_generation, outline, title_meta, recommendations, content_improvement, faq, gap_analysis
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status')->default('success'); // success, failed, timeout
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['website_id', 'feature']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
