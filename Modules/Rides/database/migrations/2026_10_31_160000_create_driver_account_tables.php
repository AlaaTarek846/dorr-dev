<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The captain's account settings (docs/rides_tasks.md D-314, D-316..D-318): a number change waiting for its code,
 * the terms each captain accepted (which version, when), and why an account was closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_phone_changes', function (Blueprint $table) {
            $table->foreignId('driver_id')->primary()->constrained('drivers')->cascadeOnDelete();
            $table->string('new_phone', 20);
            $table->string('code', 10);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();
        });
        Schema::create('driver_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('type', 16)->comment('term / privacy');
            $table->string('version', 32)->comment('the page as it was (its last change)');
            $table->string('locale', 5)->nullable();
            $table->dateTime('accepted_at');
            $table->unique(['driver_id', 'type', 'version']);
        });
        Schema::table('drivers', function (Blueprint $table) {
            $table->dateTime('closed_at')->nullable()->comment('D-316: the captain closed the account');
            $table->string('closed_reason', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('drivers', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_reason']));
        Schema::dropIfExists('driver_consents');
        Schema::dropIfExists('driver_phone_changes');
    }
};
