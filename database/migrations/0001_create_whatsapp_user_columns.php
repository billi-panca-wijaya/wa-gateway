<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds WhatsApp phone login columns to the application users table.
 * Fully defensive: inspects schema dynamically, supports custom User models,
 * and never throws errors if columns or indices already exist.
 */
return new class extends Migration
{
    private function getUserTable(): string
    {
        $modelClass = config('whatsapp-gateway.user_model', config('auth.providers.users.model', 'App\\Models\\User'));

        if (class_exists($modelClass)) {
            try {
                return (new $modelClass)->getTable();
            } catch (Throwable) {
                // Fallback to 'users' table
            }
        }

        return 'users';
    }

    public function up(): void
    {
        $tableName = $this->getUserTable();

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            // Make name & email nullable for passwordless WhatsApp login if columns exist
            try {
                if (Schema::hasColumn($tableName, 'name')) {
                    $table->string('name')->nullable()->change();
                }
                if (Schema::hasColumn($tableName, 'email')) {
                    $table->string('email')->nullable()->change();
                }
            } catch (Throwable) {
                // Ignore in environments where column modification requires doctrine/dbal or is not supported
            }

            if (! Schema::hasColumn($tableName, 'tenant_key')) {
                $table->string('tenant_key', 64)->default('default');
            }
            if (! Schema::hasColumn($tableName, 'phone_e164')) {
                $table->string('phone_e164', 20)->nullable();
            }
            if (! Schema::hasColumn($tableName, 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'onboarding_completed_at')) {
                $table->timestamp('onboarding_completed_at')->nullable();
            }
            if (! Schema::hasColumn($tableName, 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn($tableName, 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable();
            }
        });

        // Add composite index safely
        try {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unique(['tenant_key', 'phone_e164']);
            });
        } catch (Throwable) {
            // Index already exists
        }
    }

    public function down(): void
    {
        $tableName = $this->getUserTable();

        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            try {
                $table->dropUnique(['tenant_key', 'phone_e164']);
            } catch (Throwable) {
                // Index might not exist
            }

            $columnsToDrop = array_filter(
                ['tenant_key', 'phone_e164', 'phone_verified_at', 'onboarding_completed_at', 'is_active', 'last_login_at'],
                fn ($col) => Schema::hasColumn($tableName, $col)
            );

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
