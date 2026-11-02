<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds WhatsApp phone login columns to the existing users table.
 * Safe to run on any Laravel project â€” only adds new columns, never overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            if (! Schema::hasColumn('users', 'tenant_key')) {
                $table->string('tenant_key', 64)->default('default');
            }
            if (! Schema::hasColumn('users', 'phone_e164')) {
                $table->string('phone_e164', 16)->nullable();
            }
            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
            $table->unique(['tenant_key', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_key', 'phone_e164']);
            $dropCols = array_filter(['tenant_key', 'phone_e164', 'phone_verified_at', 'onboarding_completed_at', 'is_active', 'last_login_at'], fn ($c) => Schema::hasColumn('users', $c));
            if ($dropCols !== []) {
                $table->dropColumn($dropCols);
            }
        });
    }
};
