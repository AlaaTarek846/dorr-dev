<?php

use Illuminate\Support\Facades\Route;
use Modules\Marketing\Http\Controllers\BudgetController;
use Modules\Marketing\Http\Controllers\CampaignController;
use Modules\Marketing\Http\Controllers\ContentController;
use Modules\Marketing\Http\Controllers\DiscoveryController;
use Modules\Marketing\Http\Controllers\IntegrationController;
use Modules\Marketing\Http\Controllers\OptimisationController;
use Modules\Marketing\Http\Controllers\PlanController;
use Modules\Marketing\Http\Controllers\ProjectController;
use Modules\Marketing\Http\Controllers\ResearchController;
use Modules\Marketing\Http\Controllers\ResultsController;
use Modules\Marketing\Http\Controllers\StatusController;
use Modules\Marketing\Http\Controllers\TeamController;

/*
 * The marketing director for both owners (user + provider). Required from inside each audience's own group
 * (user.php / provider.php), which supplies the prefix and the auth guard. Every route but `status` needs the
 * service on in the caller's country; each feature has its own switch too (docs/marketing_tasks.md D4).
 */
Route::get('status', [StatusController::class, 'show']);

Route::middleware('marketing.feature')->group(function () {
    Route::get('projects', [ProjectController::class, 'index']);
    Route::post('projects', [ProjectController::class, 'store'])->middleware('throttle:20,1,mk-projects');
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::patch('projects/{project}', [ProjectController::class, 'update']);
    Route::post('projects/{project}/archive', [ProjectController::class, 'archive']);
    Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    Route::get('projects/{project}/jobs', [ProjectController::class, 'jobs']);
    Route::get('jobs/{job}', [ProjectController::class, 'job']);

    Route::get('projects/{project}/discovery', [DiscoveryController::class, 'show']);
    Route::put('projects/{project}/discovery', [DiscoveryController::class, 'update']);
    Route::post('projects/{project}/discovery/suggest', [DiscoveryController::class, 'suggest'])->middleware('throttle:10,1,mk-discovery');

    Route::get('projects/{project}/members', [TeamController::class, 'index']);
    Route::middleware('marketing.feature:team')->group(function () {
        Route::post('projects/{project}/members', [TeamController::class, 'store'])->middleware('throttle:30,1,mk-team');
        Route::patch('projects/{project}/members/{member}', [TeamController::class, 'update'])->whereNumber('member');
        Route::delete('projects/{project}/members/{member}', [TeamController::class, 'destroy'])->whereNumber('member');
    });
    Route::post('projects/{project}/leave', [TeamController::class, 'leave']);

    Route::get('projects/{project}/research-runs', [ResearchController::class, 'index']);
    Route::get('research-runs/{run}', [ResearchController::class, 'show']);
    Route::post('projects/{project}/research-runs', [ResearchController::class, 'store'])->middleware(['marketing.feature:research', 'throttle:10,1,mk-research']);

    Route::get('projects/{project}/plans', [PlanController::class, 'index']);
    Route::get('plans/{plan}', [PlanController::class, 'show']);
    Route::get('plans/{plan}/versions', [PlanController::class, 'versions']);
    Route::get('plans/{plan}/versions/{version}', [PlanController::class, 'version'])->whereNumber('version');
    Route::middleware('marketing.feature:plans')->group(function () {
        Route::post('projects/{project}/plans', [PlanController::class, 'store'])->middleware('throttle:10,1,mk-plans');
        Route::put('plans/{plan}', [PlanController::class, 'update']);
        Route::post('plans/{plan}/submit', [PlanController::class, 'submit']);
        Route::post('plans/{plan}/approve', [PlanController::class, 'approve']);
        Route::post('plans/{plan}/return', [PlanController::class, 'reject']);
        Route::post('plans/{plan}/archive', [PlanController::class, 'archive']);
    });
    Route::put('plans/{plan}/finance', [PlanController::class, 'finance'])->middleware('marketing.feature:finance');
    Route::get('plans/{plan}/versions/{version}/export', [PlanController::class, 'export'])->whereNumber('version')
        ->middleware(['marketing.feature:export', 'throttle:20,1,mk-export']);

    // Phase B — the calendar and the ad studio (U06/U07): nothing is published by DORR, the person posts.
    Route::middleware('marketing.feature:calendar')->group(function () {
        Route::get('projects/{project}/calendar', [ContentController::class, 'calendar']);
        Route::post('projects/{project}/calendar/generate', [ContentController::class, 'generateCalendar'])->middleware('throttle:6,1,mk-calendar');
    });
    Route::middleware('marketing.feature:content')->group(function () {
        Route::get('projects/{project}/content', [ContentController::class, 'index']);
        Route::post('projects/{project}/content', [ContentController::class, 'store'])->middleware('throttle:60,1,mk-content');
        Route::post('projects/{project}/content/generate', [ContentController::class, 'generate'])->middleware('throttle:10,1,mk-studio');
        Route::get('content/{item}', [ContentController::class, 'show']);
        Route::patch('content/{item}', [ContentController::class, 'update']);
        Route::delete('content/{item}', [ContentController::class, 'destroy']);
        Route::post('content/{item}/submit', [ContentController::class, 'submit']);
        Route::post('content/{item}/approve', [ContentController::class, 'approve']);
        Route::post('content/{item}/reject', [ContentController::class, 'reject']);
        Route::post('content/{item}/schedule', [ContentController::class, 'schedule']);
        Route::post('content/{item}/outcome', [ContentController::class, 'outcome']);
        Route::post('content/{item}/done', [ContentController::class, 'done']);
    });

    // Budgets (U08): ad spend in money, AI in calls.
    Route::middleware('marketing.feature:budgets')->group(function () {
        Route::get('projects/{project}/budgets', [BudgetController::class, 'index']);
        Route::post('projects/{project}/budgets', [BudgetController::class, 'store'])->middleware('throttle:30,1,mk-budgets');
        Route::patch('budgets/{budget}', [BudgetController::class, 'update']);
        Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve']);
        Route::post('budgets/{budget}/close', [BudgetController::class, 'close']);
    });

    // Results, KPI board and executive report (U11/U12).
    Route::middleware('marketing.feature:results')->group(function () {
        Route::get('projects/{project}/metrics', [ResultsController::class, 'index']);
        Route::post('projects/{project}/metrics', [ResultsController::class, 'store'])->middleware('throttle:30,1,mk-metrics');
        Route::post('projects/{project}/metrics/import', [ResultsController::class, 'import'])->middleware('throttle:10,1,mk-import');
        Route::delete('metrics/{metric}', [ResultsController::class, 'destroy'])->whereNumber('metric');
        Route::get('projects/{project}/kpis', [ResultsController::class, 'kpis']);
        Route::get('projects/{project}/executive-report', [ResultsController::class, 'report']);
    });
    // Phase C — ad platforms (U10) and campaigns (U09). Nothing reaches a platform without an approval of that exact
    // version and an Idempotency-Key; campaigns are created PAUSED.
    Route::middleware('marketing.feature:platforms')->group(function () {
        Route::get('projects/{project}/integrations', [IntegrationController::class, 'index']);
        Route::post('projects/{project}/integrations/{provider}/connect', [IntegrationController::class, 'connect'])->middleware('throttle:10,1,mk-connect');
        Route::get('integrations/{integration}', [IntegrationController::class, 'show']);
        Route::post('integrations/{integration}/account', [IntegrationController::class, 'account']);
        Route::post('integrations/{integration}/disconnect', [IntegrationController::class, 'disconnect']);
    });
    Route::middleware('marketing.feature:campaigns')->group(function () {
        Route::get('projects/{project}/campaigns', [CampaignController::class, 'index']);
        Route::post('projects/{project}/campaigns', [CampaignController::class, 'store'])->middleware('throttle:30,1,mk-campaigns');
        Route::get('campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::patch('campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy']);
        Route::post('campaigns/{campaign}/validate', [CampaignController::class, 'validateCampaign']);
        Route::post('campaigns/{campaign}/approval-requests', [CampaignController::class, 'requestApproval']);
        Route::post('campaigns/{campaign}/approve', [CampaignController::class, 'approve']);
        Route::post('campaigns/{campaign}/reject', [CampaignController::class, 'reject']);
        Route::post('campaigns/{campaign}/publish', [CampaignController::class, 'publish'])->middleware('throttle:10,1,mk-publish');
        Route::post('campaigns/{campaign}/reconcile', [CampaignController::class, 'reconcile'])->middleware('throttle:20,1,mk-reconcile');
        Route::post('campaigns/{campaign}/metrics/sync', [CampaignController::class, 'sync'])->middleware('throttle:10,1,mk-sync');
        Route::get('campaigns/{campaign}/metrics', [CampaignController::class, 'metrics']);
        Route::get('projects/{project}/delegations', [CampaignController::class, 'delegations']);
        Route::post('projects/{project}/delegations', [CampaignController::class, 'delegate'])->middleware('throttle:20,1,mk-delegations');
        Route::delete('delegations/{delegation}', [CampaignController::class, 'revokeDelegation'])->whereUuid('delegation');
    });
    // Phase D — experiments, alert rules, suggested optimisations, simulation. Nothing here changes spending.
    Route::middleware('marketing.feature:optimisation')->group(function () {
        Route::get('projects/{project}/experiments', [OptimisationController::class, 'experiments']);
        Route::post('projects/{project}/experiments', [OptimisationController::class, 'storeExperiment'])->middleware('throttle:30,1,mk-experiments');
        Route::post('experiments/{experiment}/start', [OptimisationController::class, 'startExperiment']);
        Route::post('experiments/{experiment}/results', [OptimisationController::class, 'recordExperiment']);
        Route::post('experiments/{experiment}/conclude', [OptimisationController::class, 'concludeExperiment']);
        Route::get('projects/{project}/alert-rules', [OptimisationController::class, 'alertRules']);
        Route::post('projects/{project}/alert-rules', [OptimisationController::class, 'storeAlertRule'])->middleware('throttle:30,1,mk-alerts');
        Route::patch('alert-rules/{rule}', [OptimisationController::class, 'updateAlertRule']);
        Route::delete('alert-rules/{rule}', [OptimisationController::class, 'destroyAlertRule']);
        Route::get('projects/{project}/optimisations', [OptimisationController::class, 'optimisations']);
        Route::post('plans/{plan}/simulate', [OptimisationController::class, 'simulate'])->middleware('throttle:30,1,mk-simulate');
    });
});
