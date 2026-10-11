<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Call recording (spec 92, safety rule 1): a recording starts only once everyone in the call has
 * agreed — one "no", or silence until the deadline, and nothing is recorded. Someone joining a call
 * that records agrees before they're let in. LiveKit Egress records the room into our S3-compatible
 * bucket; only the people who agreed can play it, and it's deleted after the admin's retention days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_call_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('call_id')->constrained('chat_calls')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('requested_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->string('status', 12)->default('pending')->comment('pending · recording · processing · ready · declined · expired · cancelled · failed · deleted');
            $table->timestamp('consent_until')->nullable()->comment('a pending request is dropped after this');
            $table->string('egress_id', 64)->nullable()->index();
            $table->string('file_path')->nullable()->comment('key in the recordings bucket');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('stopped_by_participant_id')->nullable()->constrained('chat_participants')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->comment('the file is deleted then');
            $table->timestamps();
            $table->index(['call_id', 'status']);
            $table->index('status');
        });

        Schema::create('chat_call_recording_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained('chat_call_recordings')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('chat_participants')->cascadeOnDelete();
            $table->string('decision', 10)->comment('accepted · declined');
            $table->timestamps();
            $table->unique(['recording_id', 'participant_id'], 'chat_recording_consent_unique');
        });

        Schema::table('chat_settings', function (Blueprint $table) {
            $table->boolean('call_recording_enabled')->default(true)->after('calls_disabled_countries');
            $table->unsignedSmallInteger('call_recording_retention_days')->default(30)->after('call_recording_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('chat_settings', function (Blueprint $table) {
            $table->dropColumn(['call_recording_enabled', 'call_recording_retention_days']);
        });
        Schema::dropIfExists('chat_call_recording_consents');
        Schema::dropIfExists('chat_call_recordings');
    }
};
