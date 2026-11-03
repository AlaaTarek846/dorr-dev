<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which notices of an educational / work group a member doesn't want pushed (docs/groups_tasks.md
 * GR-009) — one row per muted kind of notice. No row: pushed. The live event still arrives while
 * the app is open; only the push is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_group_notification_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('type', 30)->comment('GroupNotifier::TYPES');
            $table->timestamps();

            $table->unique(['participant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_notification_prefs');
    }
};
