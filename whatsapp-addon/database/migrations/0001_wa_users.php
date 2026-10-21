<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds WhatsApp phone login columns to the existing users table.
 * Safe to run on any Laravel project — only adds new columns, never overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('tenant_key', 64)->default('default');
            $table->string('phone_e164', 16)->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('onboarding_completed_at')->nullable();
            $table->unique(['tenant_key', 'phone_e164']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_key', 'phone_e164']);
            $table->dropColumn(['tenant_key', 'phone_e164', 'phone_verified_at', 'onboarding_completed_at']);
        });
    }
};
