<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deploy_projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_gid');
            $table->string('name');
            $table->string('stage');
            $table->string('type'); // back, front
            $table->string('created_from'); // bot, panel
            $table->string('status');
            $table->string('current_step')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('deploy_payload');
            $table->json('logs')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->unsignedInteger('fail_counts')->nullable();
            $table->dateTime('next_try_at')->nullable();
            $table->json('exception')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploy_projects');
    }
};
