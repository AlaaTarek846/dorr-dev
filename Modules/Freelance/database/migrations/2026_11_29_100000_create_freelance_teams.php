<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-201: a freelancers' team (docs/modules/freelance/TEAMS-ERD.md §2). The team is the unit that works in front of the client and its Owner
 * is the legal side (D2); the members and their roles live in their own table and are never deleted (they only leave or are removed, so the
 * history keeps who was there). Invitations only get their table here; the flow that uses it is FS-203.
 *
 * Only what the model needs now: the profile (logo, bio, specialties, visibility, verification) is FS-202, the team on offers and agreements
 * is FS-205 - each adds its own nullable columns later, so nothing running changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_teams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // The country whose switches and limits govern the team: its creator's country.
            $table->foreignId('country_id')->constrained('countries')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('status', 20)->default('active')->comment('active | suspended | archived');
            $table->foreignId('created_by_freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_id', 'status']);
        });

        Schema::create('freelance_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('freelance_teams')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('role', 20)->default('member')->comment('owner | pm | member');
            $table->string('status', 20)->default('active')->comment('active | left | removed');
            // Per-member changes to what the role allows (FS-204); null = the role's defaults.
            $table->json('permissions')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->string('left_reason', 100)->nullable();
            $table->foreignId('removed_by_freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete();
            $table->timestamps();

            // One row per person and team: coming back after leaving reuses it.
            $table->unique(['team_id', 'freelancer_id']);
            $table->index(['freelancer_id', 'status']);
            $table->index(['team_id', 'status']);
        });

        Schema::create('freelance_team_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('team_id')->constrained('freelance_teams')->cascadeOnDelete();
            // Null = an invitation by link (anyone holding the token may answer it).
            $table->foreignId('freelancer_id')->nullable()->constrained('freelancers')->cascadeOnDelete();
            $table->string('token', 64)->nullable()->unique();
            $table->string('role', 20)->default('member')->comment('pm | member');
            $table->foreignId('invited_by_freelancer_id')->nullable()->constrained('freelancers')->nullOnDelete();
            $table->string('status', 20)->default('pending')->comment('pending | accepted | declined | expired | cancelled');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['freelancer_id', 'status']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_team_invitations');
        Schema::dropIfExists('freelance_team_members');
        Schema::dropIfExists('freelance_teams');
    }
};
