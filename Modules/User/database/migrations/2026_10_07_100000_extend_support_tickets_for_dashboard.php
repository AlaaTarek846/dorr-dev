<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tickets become a conversation between the customer and the support team (dashboard):
     * a status that moves (opened, resolved/closed, reopened), an assigned admin, a status
     * history, and messages that can carry a photo and come from a named admin.
     */
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->after('status');
            $table->index('status');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained('admins')->nullOnDelete();
            $table->string('image_path')->nullable()->after('body');
            $table->text('body')->nullable()->change();
        });

        Schema::create('support_ticket_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            // Who moved it: the customer or the support team.
            $table->string('actor', 20)->default('user');
            $table->string('status', 20);
            $table->timestamps();
        });

        // The old "open" status is "opened" now; the old general live-chat thread (no ticket) is gone.
        DB::table('support_tickets')->where('status', 'open')->update(['status' => 'opened']);
        DB::table('support_messages')->whereNull('support_ticket_id')->delete();

        // A ticket's first message is what the customer wrote when opening it.
        DB::table('support_tickets')->orderBy('id')->each(function ($ticket) {
            $has = DB::table('support_messages')->where('support_ticket_id', $ticket->id)->exists();

            if (! $has) {
                DB::table('support_messages')->insert([
                    'user_id' => $ticket->user_id,
                    'support_ticket_id' => $ticket->id,
                    'sender' => 'user',
                    'body' => $ticket->body,
                    'image_path' => $ticket->image_path,
                    'created_at' => $ticket->created_at,
                    'updated_at' => $ticket->created_at,
                ]);
            }

            DB::table('support_ticket_activities')->insert([
                'support_ticket_id' => $ticket->id,
                'actor' => 'user',
                'status' => $ticket->status,
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->created_at,
            ]);

            DB::table('support_tickets')->where('id', $ticket->id)->update([
                'last_message_at' => DB::table('support_messages')->where('support_ticket_id', $ticket->id)->max('created_at') ?? $ticket->created_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_activities');

        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_id');
            $table->dropColumn('image_path');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('admin_id');
            $table->dropColumn('last_message_at');
        });
    }
};
