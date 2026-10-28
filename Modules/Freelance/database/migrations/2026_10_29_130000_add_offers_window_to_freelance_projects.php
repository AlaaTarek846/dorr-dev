<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-204: how long a published project takes offers. The deadline is worked out when it is published (from the policy then, so a
 * later change of the policy never touches a project already open); closing the window is a mark of its own — the project stays
 * `published`, only the offers stop — and it can be opened again (a few times for the client, without a limit for an admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->timestamp('offers_deadline_at')->nullable()->after('published_at')->index();
            $table->timestamp('offers_closed_at')->nullable()->after('offers_deadline_at');
            $table->string('offers_close_reason', 20)->nullable()->after('offers_closed_at')->comment('deadline | client | admin');
            $table->unsignedTinyInteger('reopen_count')->default(0)->after('offers_close_reason');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_projects', function (Blueprint $table) {
            $table->dropColumn(['offers_deadline_at', 'offers_closed_at', 'offers_close_reason', 'reopen_count']);
        });
    }
};
