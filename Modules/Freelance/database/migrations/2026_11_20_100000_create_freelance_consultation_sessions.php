<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-L16: the consultation's video session. One LiveKit room per booked consultation, open from a few minutes before the start to its end
 * (then the server closes it). Both sides agree to the recording when they book / accept; every stretch of the session is recorded into
 * the bucket, moved to our own storage, offered to both sides to download, shown to the staff in a complaint, and deleted a week after
 * the end (a week after the staff's decision when there is a complaint). Who came in and left, and who watched a recording, is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->timestamp('recording_consent_at')->nullable()->after('topic')->comment('the client agreed to the recording when booking');
            $table->timestamp('freelancer_consent_at')->nullable()->after('recording_consent_at')->comment('the freelancer agreed when accepting');
            $table->string('room_name', 64)->nullable()->after('freelancer_consent_at');
            $table->timestamp('session_opened_at')->nullable()->after('room_name')->comment('the first one came in');
            $table->timestamp('session_closed_at')->nullable()->after('session_opened_at')->comment('the server (time over) or the staff closed the room');
            $table->string('session_closed_by', 12)->nullable()->after('session_closed_at')->comment('time | staff');
            $table->timestamp('ending_told_at')->nullable()->after('session_closed_by')->comment('both were told it ends in a few minutes');
            $table->timestamp('recordings_delete_at')->nullable()->after('ending_told_at')->comment('null while a complaint holds them');
            $table->timestamp('recordings_deleted_at')->nullable()->after('recordings_delete_at');
        });

        Schema::create('freelance_consultation_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('freelance_consultations')->cascadeOnDelete();
            $table->string('side', 12)->comment('client | freelancer');
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable()->comment('null: still in, or the app closed without saying (closed with the room)');
            $table->timestamps();

            $table->index(['consultation_id', 'side']);
        });

        Schema::create('freelance_consultation_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('consultation_id')->constrained('freelance_consultations')->cascadeOnDelete();
            $table->unsignedSmallInteger('part')->default(1)->comment('a session joined again after the room emptied is recorded in a new part');
            $table->string('status', 12)->default('recording')->comment('recording | processing | moving | stored | failed | deleted');
            $table->string('egress_id', 64)->nullable();
            $table->string('bucket_key')->nullable()->comment('where LiveKit put it (gone once moved to our storage)');
            $table->string('file_path')->nullable()->comment('on our private disk');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('stored_at')->nullable();
            $table->string('error', 250)->nullable();
            $table->timestamps();

            $table->index(['consultation_id', 'part']);
            $table->index('status');
        });

        Schema::create('freelance_consultation_recording_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recording_id')->constrained('freelance_consultation_recordings')->cascadeOnDelete();
            $table->string('viewer_type', 12)->comment('client | freelancer | staff');
            $table->unsignedBigInteger('viewer_id');
            $table->string('action', 10)->default('download')->comment('download | watch');
            $table->timestamp('created_at')->nullable();

            $table->index('recording_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_consultation_recording_views');
        Schema::dropIfExists('freelance_consultation_recordings');
        Schema::dropIfExists('freelance_consultation_attendance');
        Schema::table('freelance_consultations', function (Blueprint $table) {
            $table->dropColumn(['recording_consent_at', 'freelancer_consent_at', 'room_name', 'session_opened_at', 'session_closed_at', 'session_closed_by', 'ending_told_at', 'recordings_delete_at', 'recordings_deleted_at']);
        });
    }
};
