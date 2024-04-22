<?php

use DefStudio\Telegraph\Models\TelegraphChat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained((new TelegraphChat())->getTable())->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('current_command')->nullable();
            $table->json('state');
            $table->json('context_data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_contexts');
    }
};
