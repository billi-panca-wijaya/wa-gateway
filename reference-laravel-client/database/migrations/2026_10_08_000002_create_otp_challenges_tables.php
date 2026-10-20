<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('tenant_key', 64);
            $table->string('phone_e164', 16);
            $table->string('purpose', 32)->default('login');
            $table->string('verifier', 64)->nullable();
            $table->string('state', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts');
            $table->timestamp('expires_at');
            $table->timestamp('resend_available_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('superseded_by', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamps();

            $table->index(['tenant_key', 'phone_e164', 'state']);
            $table->unique(['tenant_key', 'idempotency_key']);
        });

        Schema::create('otp_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('challenge_id', 64)->index();
            $table->string('transport', 32);
            $table->string('sender_ref', 128)->nullable();
            $table->string('idempotency_key', 128);
            $table->string('provider_message_id', 191)->nullable();
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_deliveries');
        Schema::dropIfExists('otp_challenges');
    }
};
