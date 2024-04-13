<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_telegram_enabled')->nullable();
            $table->string('telegram_id')->nullable();
            $table->json('telegram_user')->nullable();
            $table->string('telegram_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_telegram_enabled');
            $table->dropColumn('telegram_id');
            $table->dropColumn('telegram_user');
            $table->dropColumn('telegram_token');
        });
    }
};
