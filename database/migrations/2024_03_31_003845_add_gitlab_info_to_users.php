<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gitlab_token')->nullable();
            $table->string('gitlab_id')->nullable();
            $table->string('avatar_url')->nullable();
        });
    }
};
