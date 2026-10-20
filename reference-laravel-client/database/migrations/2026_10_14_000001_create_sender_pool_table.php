<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_pool', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_key', 64)->default('default');
            $table->string('session_id', 128)->comment('OpenWA session ID, e.g. otp-sender-1');
            $table->string('label', 128)->nullable()->comment('Human-readable label for admin UI');
            $table->text('api_key_encrypted')->nullable()->comment('Optional OpenWA API key, encrypted via Laravel Crypt');
            $table->string('status', 24)->default('active')->comment('active | suspended | disconnected');
            $table->string('phone_e164', 20)->nullable()->comment('Resolved phone after pairing, e.g. +6281234567890');
            $table->string('push_name', 128)->nullable()->comment('WhatsApp push name of the sender');
            $table->timestamp('last_used_at')->nullable()->comment('Last time this sender was selected for OTP dispatch');
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 255)->nullable();
            $table->unsignedInteger('fail_count')->default(0)->comment('Consecutive failures since last success');
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
