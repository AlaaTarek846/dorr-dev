<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channels (conversation type `channel`) reuse chat_groups for their name, description and
     * photo. A public channel can be found by name or by its @handle and followed without an invite.
     */
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->string('handle', 40)->nullable()->unique()->after('description')->comment('channels: @handle');
            $table->boolean('is_public')->default(false)->after('handle')->comment('channels: listed in discover');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn(['handle', 'is_public']);
        });
    }
};
