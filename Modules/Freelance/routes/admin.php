<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceDocumentTypeController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceFeatureRuleController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceProjectReviewController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceReportController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceOversightController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceVerificationController;
use Modules\Freelance\Http\Controllers\Admin\FreelancePolicyController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceSkillController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceSpecialtyController;
use Modules\Freelance\Http\Controllers\Admin\FreelancerAdminController;

// Admin → Freelance (docs/freelance_tasks.md): filled in by the tasks of batches 1 and 8.
Route::middleware('locale')->prefix('admin/v1')->group(function () {
    Route::middleware('auth:admin_api')->group(function () {
        Route::get('freelancers/options', [FreelancerAdminController::class, 'options']);
        Route::get('freelancers', [FreelancerAdminController::class, 'index']);
        Route::get('freelancers/{freelancer}', [FreelancerAdminController::class, 'show'])->whereNumber('freelancer');
        Route::post('freelancers/{freelancer}/portfolio/{item}/hide', [FreelancerAdminController::class, 'hidePortfolio'])->whereNumber(['freelancer', 'item']);
        Route::post('freelancers/{freelancer}/portfolio/{item}/unhide', [FreelancerAdminController::class, 'unhidePortfolio'])->whereNumber(['freelancer', 'item']);
        Route::post('freelancers/{freelancer}/status', [FreelancerAdminController::class, 'status'])->whereNumber('freelancer');

        // Verification (FL-107 / FL-108): the document types per country, and the requests to decide.
        Route::get('freelance-document-types/guide', [FreelanceDocumentTypeController::class, 'guide']);
        Route::get('freelance-document-types/options', [FreelanceDocumentTypeController::class, 'options']);
        Route::get('freelance-document-types', [FreelanceDocumentTypeController::class, 'index']);
        Route::post('freelance-document-types', [FreelanceDocumentTypeController::class, 'store']);
        Route::put('freelance-document-types/{documentType}', [FreelanceDocumentTypeController::class, 'update'])->whereNumber('documentType');
        Route::patch('freelance-document-types/{documentType}/status', [FreelanceDocumentTypeController::class, 'status'])->whereNumber('documentType');
        Route::delete('freelance-document-types/{documentType}', [FreelanceDocumentTypeController::class, 'destroy'])->whereNumber('documentType');
        Route::get('freelance-verifications/guide', [FreelanceVerificationController::class, 'guide']);
        Route::get('freelance-verifications/options', [FreelanceVerificationController::class, 'options']);
        Route::get('freelance-verifications', [FreelanceVerificationController::class, 'index']);
        Route::get('freelance-verifications/documents/{document}/{side}', [FreelanceVerificationController::class, 'file'])->whereNumber('document')->whereIn('side', ['front', 'back']);
        Route::get('freelance-verifications/{uuid}', [FreelanceVerificationController::class, 'show'])->whereUuid('uuid');
        Route::post('freelance-verifications/{uuid}/decide', [FreelanceVerificationController::class, 'decide'])->whereUuid('uuid');

        // The project money and its reconciliation (FL-512).
        Route::get('freelance-reports/summary', [FreelanceReportController::class, 'summary']);
        Route::get('freelance-reports/reconcile', [FreelanceReportController::class, 'reconcile']);
        Route::get('freelance-oversight/projects', [FreelanceOversightController::class, 'projects']);
        Route::get('freelance-oversight/offers', [FreelanceOversightController::class, 'offers']);
        Route::get('freelance-oversight/agreements', [FreelanceOversightController::class, 'agreements']);
        Route::get('freelance-oversight/late', [FreelanceOversightController::class, 'late']);
        Route::get('freelance-oversight/countries', [FreelanceOversightController::class, 'countries']);

        // Support, operations, improvement, risk and the audit log (FL-803, FL-805, FL-806, FL-807, FL-808).
        Route::get('freelance-support/tickets', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSupportController::class, 'index']);
        Route::post('freelance-support/tickets/{number}/link', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSupportController::class, 'link'])->whereNumber('number');
        Route::get('freelance-reports/operations', [\Modules\Freelance\Http\Controllers\Admin\FreelanceInsightsController::class, 'operations']);
        Route::get('freelance-reports/improvement', [\Modules\Freelance\Http\Controllers\Admin\FreelanceInsightsController::class, 'improvement']);
        Route::get('freelance-risk/overview', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRiskController::class, 'overview']);
        Route::get('freelance-risk/accounts', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRiskController::class, 'accounts']);
        Route::get('freelance-audit', [\Modules\Freelance\Http\Controllers\Admin\FreelanceAuditController::class, 'index']);
        Route::get('freelance-reports/cancel-reasons', [FreelanceReportController::class, 'cancelReasons']);

        // Objections to ratings and the satisfaction survey (FL-706 / FL-702).
        Route::get('freelance-reviews/objections', [\Modules\Freelance\Http\Controllers\Admin\FreelanceReviewAdminController::class, 'objections']);
        Route::post('freelance-reviews/objections/{uuid}/decide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceReviewAdminController::class, 'decide'])->whereUuid('uuid');
        Route::get('freelance-reviews/satisfaction', [\Modules\Freelance\Http\Controllers\Admin\FreelanceReviewAdminController::class, 'satisfaction']);

        // Reported chat messages (FL-709).
        Route::get('freelance-message-reports', [\Modules\Freelance\Http\Controllers\Admin\FreelanceMessageReportController::class, 'index']);
        Route::get('freelance-message-reports/{uuid}/file', [\Modules\Freelance\Http\Controllers\Admin\FreelanceMessageReportController::class, 'file'])->whereUuid('uuid');
        Route::post('freelance-message-reports/{uuid}/decide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceMessageReportController::class, 'decide'])->whereUuid('uuid');

        // Warnings, limits and suspensions, their appeals, and the quality / risk of a person (FL-708 / FL-707).
        Route::get('freelance-sanctions', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'index']);
        Route::post('freelance-sanctions', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'store']);
        Route::get('freelance-sanctions/clients', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'clients']);
        Route::get('freelance-sanctions/people', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'people']);
        Route::get('freelance-sanctions/person/{side}/{id}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'person'])->whereIn('side', ['client', 'freelancer'])->whereNumber('id');
        Route::post('freelance-sanctions/{uuid}/lift', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'lift'])->whereUuid('uuid');
        Route::post('freelance-sanctions/{uuid}/appeal', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSanctionAdminController::class, 'decideAppeal'])->whereUuid('uuid');

        // The disputes desk (FL-705).
        Route::get('freelance-disputes', [FreelanceDisputeController::class, 'index']);
        Route::get('freelance-disputes/{uuid}', [FreelanceDisputeController::class, 'show'])->whereUuid('uuid');
        // FL-606: the evidence timeline of a case, its CSV, and the delivered files of the disputed part.
        Route::get('freelance-disputes/{uuid}/timeline', [FreelanceDisputeController::class, 'timeline'])->whereUuid('uuid');
        Route::get('freelance-disputes/{uuid}/timeline/export', [FreelanceDisputeController::class, 'exportTimeline'])->whereUuid('uuid');
        Route::get('freelance-disputes/{uuid}/deliveries/{delivery}/files/{media}', [FreelanceDisputeController::class, 'deliveryFile'])->whereUuid(['uuid', 'delivery'])->whereNumber('media');
        Route::post('freelance-disputes/{uuid}/decide', [FreelanceDisputeController::class, 'decide'])->whereUuid('uuid');
        Route::post('freelance-disputes/{uuid}/classify', [FreelanceDisputeController::class, 'classify'])->whereUuid('uuid');
        Route::get('freelance-disputes/{uuid}/files/{media}', [FreelanceDisputeController::class, 'file'])->whereUuid('uuid')->whereNumber('media');

        // Reviewing the clients' projects (FL-203): the queue, one project with its files, a fix, and the decision.
        Route::get('freelance-projects/options', [FreelanceProjectReviewController::class, 'options']);
        Route::get('freelance-projects', [FreelanceProjectReviewController::class, 'index']);
        Route::get('freelance-projects/{uuid}', [FreelanceProjectReviewController::class, 'show'])->whereUuid('uuid');
        Route::put('freelance-projects/{uuid}', [FreelanceProjectReviewController::class, 'update'])->whereUuid('uuid');
        Route::post('freelance-projects/{uuid}/publish', [FreelanceProjectReviewController::class, 'publish'])->whereUuid('uuid');
        Route::post('freelance-projects/{uuid}/request-changes', [FreelanceProjectReviewController::class, 'requestChanges'])->whereUuid('uuid');
        Route::post('freelance-projects/{uuid}/offers/reopen', [FreelanceProjectReviewController::class, 'reopenOffers'])->whereUuid('uuid');
        Route::post('freelance-projects/{uuid}/offers/close', [FreelanceProjectReviewController::class, 'closeOffers'])->whereUuid('uuid');
        Route::post('freelance-projects/{uuid}/reject', [FreelanceProjectReviewController::class, 'reject'])->whereUuid('uuid');
        Route::get('freelance-projects/{uuid}/trail', [FreelanceProjectReviewController::class, 'trail'])->whereUuid('uuid');
        Route::get('freelance-projects/{uuid}/attachments/{media}', [FreelanceProjectReviewController::class, 'attachment'])->whereUuid('uuid')->whereNumber('media');

        // Prices & rules (FL-008): one scope at a time, a preview, and copying between countries.
        Route::get('freelance-policies/options', [FreelancePolicyController::class, 'options']);
        Route::get('freelance-policies/scopes', [FreelancePolicyController::class, 'scopes']);
        Route::get('freelance-policies', [FreelancePolicyController::class, 'show']);
        Route::put('freelance-policies', [FreelancePolicyController::class, 'update']);
        Route::get('freelance-policies/guide', [FreelancePolicyController::class, 'guide']);
        Route::post('freelance-policies/preview', [FreelancePolicyController::class, 'preview']);
        Route::post('freelance-policies/copy', [FreelancePolicyController::class, 'copy']);

        // Skills (FL-102): the catalog, a freelancer's suggestions to decide on, and merging duplicates.
        Route::get('freelance-skills/options', [FreelanceSkillController::class, 'options']);
        Route::post('freelance-skills/delete-multiple', [FreelanceSkillController::class, 'deleteMultiple']);
        Route::patch('freelance-skills/{freelance_skill}/status', [FreelanceSkillController::class, 'status'])->whereNumber('freelance_skill');
        Route::post('freelance-skills/{freelance_skill}/approve', [FreelanceSkillController::class, 'approve'])->whereNumber('freelance_skill');
        Route::post('freelance-skills/{freelance_skill}/reject', [FreelanceSkillController::class, 'reject'])->whereNumber('freelance_skill');
        Route::post('freelance-skills/{freelance_skill}/merge', [FreelanceSkillController::class, 'merge'])->whereNumber('freelance_skill');
        Route::apiResource('freelance-skills', FreelanceSkillController::class)->parameters(['freelance-skills' => 'freelance_skill']);

        // Specialties (FL-101): a specialty and its sub-specialties.
        Route::post('freelance-specialties/delete-multiple', [FreelanceSpecialtyController::class, 'deleteMultiple']);
        Route::put('freelance-specialties/reorder', [FreelanceSpecialtyController::class, 'reorder']);
        Route::patch('freelance-specialties/{freelance_specialty}/status', [FreelanceSpecialtyController::class, 'status'])->whereNumber('freelance_specialty');
        Route::apiResource('freelance-specialties', FreelanceSpecialtyController::class)->parameters(['freelance-specialties' => 'freelance_specialty']);

        Route::get('freelance-feature-rules/options', [FreelanceFeatureRuleController::class, 'options']);
        Route::get('freelance-feature-rules/guide', [FreelanceFeatureRuleController::class, 'guide']);
        Route::post('freelance-feature-rules/copy', [FreelanceFeatureRuleController::class, 'copy']);
        Route::get('freelance-feature-rules', [FreelanceFeatureRuleController::class, 'index']);
        Route::post('freelance-feature-rules', [FreelanceFeatureRuleController::class, 'store']);
    });
});
