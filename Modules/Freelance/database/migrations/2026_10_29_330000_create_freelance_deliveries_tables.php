<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-601 / FL-603 / FL-604: every delivery of a part is kept as a numbered version (a note and its files, never changed), the changes asked
 * for are written on the version they are about, and the client may list what "accepted" means for a part (a checklist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('1, 2, 3 … per part');
            $table->text('note')->nullable();
            $table->string('revision_reason', 1000)->nullable()->comment('what the client asked to change in this version');
            $table->timestamp('revision_requested_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['milestone_id', 'number']);
        });

        Schema::create('freelance_milestone_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('milestone_id')->constrained('freelance_milestones')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('text', 200);
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->index(['milestone_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_milestone_checks');
        Schema::dropIfExists('freelance_deliveries');
    }
};
