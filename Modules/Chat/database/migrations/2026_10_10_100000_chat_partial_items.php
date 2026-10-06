<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing the partial chat items (docs/remaining_chat.md):
 *  - 1   folders get a colour and an emoji;
 *  - 24  favourites folders: starred messages sorted into my own named folders;
 *  - 20  voice transcripts are kept (for me) so the search finds what was said;
 *  - 25  a separate PIN for one chat (hashed; 5 wrong tries lock it for a while);
 *  - 81  a @username for people, so they can be found without a phone number;
 *  - 153 the decision room: a description, a deadline, and everyone's arguments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_folders', function (Blueprint $table) {
            $table->string('color', 9)->nullable()->after('name');
            $table->string('emoji', 16)->nullable()->after('color');
        });

        Schema::create('chat_star_folders', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('name', 50);
            $table->string('emoji', 16)->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['owner_type', 'owner_id']);
        });
        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->foreignId('star_folder_id')->nullable()->after('starred_at')->constrained('chat_star_folders')->nullOnDelete();
        });

        Schema::create('chat_message_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->text('text');
            $table->timestamps();
            $table->unique(['message_id', 'participant_id']);
        });

        Schema::table('chat_participants', function (Blueprint $table) {
            $table->string('lock_pin_hash')->nullable()->after('is_locked');
            $table->unsignedTinyInteger('lock_pin_failures')->default(0)->after('lock_pin_hash');
            $table->timestamp('lock_pin_until')->nullable()->after('lock_pin_failures');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('chat_username', 32)->nullable()->unique();
        });

        Schema::table('chat_group_decisions', function (Blueprint $table) {
            $table->string('description', 1000)->nullable()->after('title');
            $table->timestamp('deadline_at')->nullable()->after('description');
        });
        Schema::create('chat_decision_arguments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('chat_group_decisions')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('option_id', 20)->nullable();
            $table->string('stance', 10)->comment('pro | con | note');
            $table->string('text', 500);
            $table->timestamps();
            $table->index('decision_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_decision_arguments');
        Schema::table('chat_group_decisions', fn (Blueprint $table) => $table->dropColumn(['description', 'deadline_at']));
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['chat_username']);
            $table->dropColumn('chat_username');
        });
        Schema::table('chat_participants', fn (Blueprint $table) => $table->dropColumn(['lock_pin_hash', 'lock_pin_failures', 'lock_pin_until']));
        Schema::dropIfExists('chat_message_transcripts');
        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->dropConstrainedForeignId('star_folder_id');
        });
        Schema::dropIfExists('chat_star_folders');
        Schema::table('chat_folders', fn (Blueprint $table) => $table->dropColumn(['color', 'emoji']));
    }
};
