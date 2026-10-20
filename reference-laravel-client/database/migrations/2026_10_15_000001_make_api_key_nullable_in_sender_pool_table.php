<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sender_pool', function (Blueprint $table) {
            $table->text('api_key_encrypted')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep nullable on rollback to avoid breaking rows without custom keys.
    }
};
