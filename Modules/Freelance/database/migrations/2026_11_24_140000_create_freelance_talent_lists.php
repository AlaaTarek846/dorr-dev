<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-103: a client's own lists of freelancers he wants to work with again (a named list, optionally for one specialty). A person is
 * in a list with a note of his own and, only if the client asks for it, a reminder to hire again. Nothing here is shown to the freelancer
 * except how many lists he is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_talent_lists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->foreignId('freelance_specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete();
            $table->timestamps();

            $table->index('user_id');
        });

        Schema::create('freelance_talent_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('freelance_talent_lists')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('note', 200)->nullable();
            $table->timestamp('remind_at')->nullable()->comment('set only when the client asks to be reminded to hire again');
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['list_id', 'freelancer_id']);
            $table->index('freelancer_id');
            $table->index(['remind_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_talent_list_items');
        Schema::dropIfExists('freelance_talent_lists');
    }
};
