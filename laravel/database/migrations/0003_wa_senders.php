<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the WhatsApp sender pool table for multi-number rotation.
 * This is a new table — no conflict with existing database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_pool', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_key', 64)->default('default');
            $table->string('session_id', 128)->comment('Gateway session ID');
            $table->string('label', 128)->nullable()->comment('Admin display label');
            $table->text('api_key_encrypted')->nullable()->comment('Encrypted API key');
            $table->string('status', 24)->default('active')->comment('active | suspended | disconnected');
            $table->string('phone_e164', 20)->nullable()->comment('Resolved phone after pairing');
            $table->string('push_name', 128)->nullable()->comment('WhatsApp push name');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 255)->nullable();
            $table->unsignedInteger('fail_count')->default(0);
            $table->timestamps();

            $table->unique(['tenant_key', 'session_id']);
            $table->index(['tenant_key', 'status', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_pool');
    }
};
