<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-709: a chat message reported by the other person of the room, for staff to review (a copy of the text is kept as evidence). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_message_reports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('message_id')->constrained('freelance_messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('freelance_conversations')->cascadeOnDelete();
            $table->string('reporter_type', 12)->comment('client | freelancer');
            $table->unsignedBigInteger('reporter_id');
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->text('message_body')->nullable()->comment('the text as it was when reported');
            $table->string('status', 12)->default('open')->comment('open | dismissed | upheld');
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->boolean('message_hidden')->default(false);
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'reporter_type', 'reporter_id'], 'fl_msg_reports_once');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_message_reports');
    }
};
