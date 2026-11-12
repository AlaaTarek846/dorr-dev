<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capacity and the waiting list (FL-L10). A freelancer may cap how many projects run at once (`capacity_max`, empty = no cap); only running
 * work counts (an active agreement — a ready-made service order is one too). When the cap is reached a client cannot order a service of
 * theirs but can wait in line for it; when a place frees, the first in line is offered it and holds it for a while.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancers', function (Blueprint $table): void {
            $table->unsignedSmallInteger('capacity_max')->nullable()->after('status')->comment('running projects at once; null = no cap');
        });

        Schema::create('freelance_service_waitlist', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('service_id')->constrained('freelance_services')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 12)->default('waiting')->comment('waiting | offered | ordered | left | expired');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('hold_until')->nullable()->comment('the place is kept for this client until then');
            $table->timestamps();

            $table->index(['freelancer_id', 'status', 'id'], 'freelance_waitlist_line');
            $table->index(['service_id', 'client_id', 'status'], 'freelance_waitlist_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_service_waitlist');
        Schema::table('freelancers', fn (Blueprint $table) => $table->dropColumn('capacity_max'));
    }
};
