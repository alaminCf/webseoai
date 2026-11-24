<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('auth_provider');
            }
            if (!Schema::hasColumn('users', 'status')) {
                $table->string('status', 20)->default('active')->after('is_super_admin');
            }
        });

        if (!Schema::hasTable('platform_api_keys')) {
            Schema::create('platform_api_keys', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 40)->default('serper')->index();
                $table->string('label', 120);
                $table->text('encrypted_key');
                $table->string('key_hint', 24)->nullable();
                $table->unsignedSmallInteger('priority')->default(10)->index();
                $table->string('status', 20)->default('active')->index(); // active, exhausted, disabled, error
                $table->unsignedInteger('monthly_limit')->default(2500);
                $table->unsignedInteger('credits_used')->default(0);
                $table->unsignedInteger('total_requests')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('exhausted_at')->nullable();
                $table->text('last_error')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('global_serp_caches')) {
            Schema::create('global_serp_caches', function (Blueprint $table) {
                $table->id();
                $table->string('cache_hash', 64)->unique();
                $table->string('provider', 40)->default('serper');
                $table->string('keyword', 255)->index();
                $table->string('country', 4)->index();
                $table->string('language', 10)->default('en');
                $table->string('device', 20)->default('desktop');
                $table->string('location', 150)->nullable();
                $table->unsignedSmallInteger('depth')->default(30);
                $table->longText('payload');
                $table->unsignedSmallInteger('pages_fetched')->default(1);
                $table->unsignedInteger('hit_count')->default(0);
                $table->unsignedInteger('credits_saved')->default(0);
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->index(['keyword', 'country', 'language', 'device']);
            });
        }

        if (!Schema::hasTable('serp_credit_usages')) {
            Schema::create('serp_credit_usages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
                $table->foreignId('platform_api_key_id')->nullable()->constrained('platform_api_keys')->nullOnDelete();
                $table->string('provider', 40)->default('serper');
                $table->string('keyword', 255);
                $table->string('country', 4)->default('US');
                $table->unsignedSmallInteger('depth')->default(30);
                $table->unsignedSmallInteger('credits_used')->default(0);
                $table->boolean('cache_hit')->default(false);
                $table->string('key_source', 30)->default('platform_pool');
                $table->timestamps();

                $table->index(['organization_id', 'created_at']);
                $table->index(['created_at', 'cache_hit']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('serp_credit_usages');
        Schema::dropIfExists('global_serp_caches');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('platform_api_keys');

        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(
                ['is_super_admin', 'status'],
                fn (string $c) => Schema::hasColumn('users', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
