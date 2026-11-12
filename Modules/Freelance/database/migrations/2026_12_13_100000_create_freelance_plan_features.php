<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FS-705: what a plan gives beyond its own columns - one row per plan and catalog feature (`config('freelance.plan_features')`, read by
 * `PlanFeatures`). No row = the plan follows the country's policy for that feature; `value` null on a limit = no cap. The features that were
 * columns before (offers, commission, badge, boost, analytics, CRM, services, the team room) stay in their columns.
 *
 * Owner's decisions (2026-11-12): Team Starter is offered, and it gives the team's work Pro's perks (commission, badge, boost, analytics,
 * CRM) - set here where the admin has not set them yet. The starting values of the new features are added for the plans that exist;
 * an admin's value is never overwritten (later deploys add what is missing through `FreelancePlanDefaults`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_plan_features', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained('freelance_plans')->cascadeOnDelete();
            $table->string('key', 60)->comment('a key of config freelance.plan_features');
            $table->bigInteger('value')->nullable()->comment('by type: switch 0/1, level, limit (null = no cap), fee in basis points');
            $table->timestamps();
            $table->unique(['plan_id', 'key']);
        });

        $starter = DB::table('freelance_plans')->where('slug', 'team_starter')->first();
        if ($starter !== null) {
            DB::table('freelance_plans')->where('id', $starter->id)->update(array_filter([
                'status' => true,
                'commission_bp' => $starter->commission_bp === null ? 600 : null,
                'badge' => ! $starter->badge ? true : null,
                'boost' => (int) $starter->boost === 0 ? 2 : null,
                'analytics' => (int) $starter->analytics === 0 ? 2 : null,
                'crm' => (int) $starter->crm === 0 ? 2 : null,
            ], fn ($v) => $v !== null) + ['updated_at' => now()]);
        }

        foreach ((array) config('freelance.plans') as $slug => $def) {
            $planId = DB::table('freelance_plans')->where('slug', $slug)->value('id');
            if ($planId === null) {
                continue;
            }
            foreach ((array) ($def['features'] ?? []) as $key => $value) {
                DB::table('freelance_plan_features')->insertOrIgnore([
                    'plan_id' => $planId, 'key' => $key, 'value' => $value === null ? null : (int) $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_plan_features');
    }
};
