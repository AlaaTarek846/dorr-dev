<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stickers people make from their own photos ("My stickers"). The image is a media item;
        // removing one only hides it from the collection — stickers already sent keep showing.
        Schema::create('chat_user_stickers', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('emoji', 16)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['owner_type', 'owner_id', 'deleted_at'], 'chat_user_stickers_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_user_stickers');
    }
};
