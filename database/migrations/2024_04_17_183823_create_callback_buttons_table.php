<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('callback_buttons', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chat_context_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->json('payload');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_buttons');
    }
};
