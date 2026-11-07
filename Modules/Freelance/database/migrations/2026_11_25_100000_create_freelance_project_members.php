<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-104: the people a client brings into one of his projects (docs/modules/freelance/PERMISSIONS.md). Each is a regular Dorr user with
 * one role; a delegate also has the most he may approve at once and the day his delegation ends. The owner stays the legal side: the
 * steps a member takes are taken for the owner, and every record row keeps who really did it (`acted_by_user_id`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_project_members', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20)->comment('manager | reviewer | collaborator | finance | delegate');
            $table->string('status', 20)->default('invited')->comment('invited | active | declined | removed | left | expired');
            $table->unsignedBigInteger('max_amount_minor')->nullable()->comment('delegate: the most approved or funded at once');
            $table->timestamp('ends_at')->nullable()->comment('delegate: the delegation ends');
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        // Who really took the step when a team member acted for the owner (null = the actor itself).
        foreach (['freelance_project_approvals', 'freelance_project_events', 'freelance_milestone_events', 'freelance_offer_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('acted_by_user_id')->nullable()->after('actor_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['freelance_project_approvals', 'freelance_project_events', 'freelance_milestone_events', 'freelance_offer_events'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('acted_by_user_id');
            });
        }
        Schema::dropIfExists('freelance_project_members');
    }
};
