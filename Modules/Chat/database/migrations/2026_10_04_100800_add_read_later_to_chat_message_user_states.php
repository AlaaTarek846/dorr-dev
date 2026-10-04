<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Read later": a message I set aside to come back to — my own list, apart from starred
     * messages (which are kept for good). Taken off the list when I'm done with it.
     */
    public function up(): void
    {
        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->timestamp('read_later_at')->nullable()->after('starred_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_user_states', function (Blueprint $table) {
            $table->dropColumn('read_later_at');
        });
    }
};
