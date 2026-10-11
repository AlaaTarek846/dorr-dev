<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Automatic replies in support tickets: an acknowledgement when a ticket opens, an "we're away"
     * note outside working hours, and an answer drawn from the FAQs by the AI module — always marked
     * as automatic, never in an agent's name. Plus the agents' quick replies ("/refund" → a ready text).
     */
    public function up(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false)->after('image_path');
            // ack · away · faq — which automatic reply this is.
            $table->string('auto_kind', 20)->nullable()->after('is_auto');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            // The customer asked for a person: no more automatic answers on this ticket.
            $table->timestamp('auto_reply_stopped_at')->nullable()->after('last_message_at');
        });

        Schema::create('support_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_reply_enabled')->default(true);
            $table->boolean('ack_enabled')->default(true);
            $table->json('ack_message')->nullable();
            $table->boolean('away_enabled')->default(true);
            $table->json('away_message')->nullable();
            // 7 days, Sunday first: {open, from, to}.
            $table->json('hours')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->unsignedSmallInteger('away_every_hours')->default(6);
            $table->boolean('ai_enabled')->default(true);
            $table->unsignedTinyInteger('ai_max_replies')->default(2);
            $table->timestamps();
        });

        Schema::create('support_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('shortcut', 40)->unique();
            $table->string('title', 120);
            $table->text('body');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_quick_replies');
        Schema::dropIfExists('support_settings');

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn('auto_reply_stopped_at');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn(['is_auto', 'auto_kind']);
        });
    }
};
