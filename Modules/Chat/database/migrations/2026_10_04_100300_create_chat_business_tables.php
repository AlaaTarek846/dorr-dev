<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business tools (WhatsApp Business style): quick replies typed with "/", and a business profile
     * with opening hours, a welcome message for new customers and an away message when closed.
     */
    public function up(): void
    {
        Schema::create('chat_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('shortcut', 32)->comment('typed after "/" in the composer');
            $table->text('body');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'shortcut'], 'chat_quick_replies_owner_shortcut_unique');
        });

        Schema::create('chat_business_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->boolean('welcome_enabled')->default(false);
            $table->text('welcome_message')->nullable();
            $table->boolean('away_enabled')->default(false);
            $table->text('away_message')->nullable();
            $table->string('away_mode', 16)->default('outside_hours')->comment('always | outside_hours');
            $table->json('hours')->nullable()->comment('7 days, Sunday first: {open, from, to}');
            $table->string('timezone', 64)->default('UTC');
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'chat_business_profiles_owner_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_business_profiles');
        Schema::dropIfExists('chat_quick_replies');
    }
};
