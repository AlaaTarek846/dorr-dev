<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FS-703: the freelancer's private CRM. The list of clients is not stored (it is read from the agreements and the offers); only what the
 * freelancer adds is: a label and a star per client, private notes, and follow-up reminders. The client never sees any of it.
 * What a plan opens is `freelance_plans.crm` (0 none, 1 clients + past projects + notes, 2 also labels and follow-up reminders).
 * FS-704: the visits to one portfolio work are counted in `freelance_profile_views` (kind `work`, `service_id` = the work's id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_crm_contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique()->comment('what the app calls the client by — the user id is never sent');
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('label', 12)->nullable()->comment('vip | regular | lead | paused');
            $table->boolean('starred')->default(false);
            $table->timestamps();
            $table->unique(['freelancer_id', 'user_id'], 'fl_crm_contact_unique');
        });

        Schema::create('freelance_crm_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 1000);
            $table->timestamps();
            $table->index(['freelancer_id', 'user_id'], 'fl_crm_note_client');
        });

        Schema::create('freelance_crm_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->dateTime('due_at');
            $table->dateTime('done_at')->nullable();
            $table->dateTime('notified_at')->nullable()->comment('the freelancer was told it is due');
            $table->timestamps();
            $table->index(['freelancer_id', 'done_at', 'due_at'], 'fl_crm_rem_mine');
            $table->index(['due_at', 'notified_at'], 'fl_crm_rem_due');
        });

        Schema::table('freelance_plans', function (Blueprint $table) {
            $table->unsignedTinyInteger('crm')->default(0)->after('analytics')->comment('FS-703: 0 none, 1 clients + notes, 2 also labels and reminders');
        });
        foreach (['plus' => 1, 'pro' => 2] as $slug => $level) {
            DB::table('freelance_plans')->where('slug', $slug)->update(['crm' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('freelance_plans', function (Blueprint $table) {
            $table->dropColumn('crm');
        });
        Schema::dropIfExists('freelance_crm_reminders');
        Schema::dropIfExists('freelance_crm_notes');
        Schema::dropIfExists('freelance_crm_contacts');
    }
};
