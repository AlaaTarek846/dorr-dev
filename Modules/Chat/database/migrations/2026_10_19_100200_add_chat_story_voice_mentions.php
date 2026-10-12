<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From the WhatsApp list in the spec's backlog: a voice status (78) — the type is a string, so it
 * needs no column — and mentioning people in a status (84), who may share it again in their own
 * (85) without it naming the original poster (86).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_stories', function (Blueprint $table) {
            $table->json('mentions')->nullable()->comment('Participant keys mentioned in it');
            $table->unsignedBigInteger('reshared_from_id')->nullable()->comment('Shared again by someone it mentioned');
        });
    }

    public function down(): void
    {
        Schema::table('chat_stories', function (Blueprint $table) {
            $table->dropColumn(['mentions', 'reshared_from_id']);
        });
    }
};
