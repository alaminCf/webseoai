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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'google_id')) {
                $table->string('google_id', 120)->nullable()->unique()->after('email');
            }
            if (!Schema::hasColumn('users', 'avatar_url')) {
                $table->text('avatar_url')->nullable()->after('google_id');
            }
            if (!Schema::hasColumn('users', 'job_title')) {
                $table->string('job_title', 120)->nullable()->after('avatar_url');
            }
            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 40)->nullable()->after('job_title');
            }
            if (!Schema::hasColumn('users', 'bio')) {
                $table->string('bio', 500)->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider', 30)->default('email')->after('bio');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter(
                ['google_id', 'avatar_url', 'job_title', 'phone', 'bio', 'auth_provider'],
                fn (string $c) => Schema::hasColumn('users', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
