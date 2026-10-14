<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communities (WhatsApp list 93–98): several groups under one roof, with an announcements group
 * only its admins write in. The community's members are that group's members; its admins (up to
 * 20) are that group's admins; its creator is that group's owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_communities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('creator_type', 20);
            $table->unsignedBigInteger('creator_id');
            $table->unsignedBigInteger('announcement_conversation_id')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('chat_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('community_id')->nullable()->index()->after('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropIndex(['community_id']);
            $table->dropColumn('community_id');
        });
        Schema::dropIfExists('chat_communities');
    }
};
