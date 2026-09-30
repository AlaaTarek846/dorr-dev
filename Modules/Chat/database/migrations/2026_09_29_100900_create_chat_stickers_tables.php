<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dorr's own sticker packs, made by the admin (cover + stickers are media). GIFs and the big
     * animated sticker library come from Giphy and are never stored here.
     */
    public function up(): void
    {
        Schema::create('chat_sticker_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_sticker_pack_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_sticker_pack_id')->constrained('chat_sticker_packs')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 100);
            $table->timestamps();

            // Named by hand: the generated name is over MySQL's 64-character limit.
            $table->unique(['chat_sticker_pack_id', 'locale'], 'chat_sticker_pack_tr_unique');
        });

        Schema::create('chat_stickers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('chat_sticker_packs')->cascadeOnDelete();
            $table->string('emoji', 32)->nullable()->comment('what it means — used to suggest it');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_stickers');
        Schema::dropIfExists('chat_sticker_pack_translations');
        Schema::dropIfExists('chat_sticker_packs');
    }
};
