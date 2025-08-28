<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['device_key']);
            $table->unique(['user_id', 'device_key']);
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['device_tokens_user_id_device_key_unique']);
            $table->unique('device_key');
        });
    }
};
