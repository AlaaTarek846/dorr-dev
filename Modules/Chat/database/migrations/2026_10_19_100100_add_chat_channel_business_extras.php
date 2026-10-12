<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From the WhatsApp list in the spec's backlog: the reactions a channel allows (110), and a
 * business's profile — about, address, email, website (111).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->json('allowed_reactions')->nullable()->comment('A channel: the emojis followers may react with (null = any, [] = none)');
        });

        Schema::table('chat_business_profiles', function (Blueprint $table) {
            $table->string('about', 500)->nullable();
            $table->string('address', 300)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('website', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_business_profiles', function (Blueprint $table) {
            $table->dropColumn(['about', 'address', 'email', 'website']);
        });
        Schema::table('chat_groups', function (Blueprint $table) {
            $table->dropColumn('allowed_reactions');
        });
    }
};
