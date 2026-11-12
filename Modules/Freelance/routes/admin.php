<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceChecklistTemplateController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceDocumentTypeController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceFeatureRuleController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceProjectReviewController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceReportController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceOversightController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceVerificationController;
use Modules\Freelance\Http\Controllers\Admin\FreelancePolicyController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceSkillController;
use Modules\Freelance\Http\Controllers\Admin\FreelanceSkillTestController;
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
        // Checklist templates (FS-105): ready-made lists of what a client checks before approving a part.
        Route::get('freelance-checklist-templates/options', [FreelanceChecklistTemplateController::class, 'options']);
        Route::get('freelance-checklist-templates', [FreelanceChecklistTemplateController::class, 'index']);
        Route::post('freelance-checklist-templates', [FreelanceChecklistTemplateController::class, 'store']);
        Route::put('freelance-checklist-templates/{template}', [FreelanceChecklistTemplateController::class, 'update'])->whereNumber('template');
        Route::patch('freelance-checklist-templates/{template}/status', [FreelanceChecklistTemplateController::class, 'status'])->whereNumber('template');
        Route::delete('freelance-checklist-templates/{template}', [FreelanceChecklistTemplateController::class, 'destroy'])->whereNumber('template');

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
        // FL-L01: the plans, their prices per country and who is subscribed.
        Route::get('freelance-plans', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'index']);
        Route::post('freelance-plans/fill-prices', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'fillPrices']);
        Route::get('freelance-plans/subscriptions', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'subscriptions']);
        // FL-L01 phase 2: the packs of extra offers, their price per country and who bought them.
        Route::get('freelance-plans/packs', [\Modules\Freelance\Http\Controllers\Admin\FreelanceOfferPackController::class, 'index']);
        Route::get('freelance-plans/pack-purchases', [\Modules\Freelance\Http\Controllers\Admin\FreelanceOfferPackController::class, 'purchases']);
        Route::put('freelance-plans/packs/{pack}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceOfferPackController::class, 'update'])->whereNumber('pack');
        Route::get('freelance-plans/packs/{pack}/prices', [\Modules\Freelance\Http\Controllers\Admin\FreelanceOfferPackController::class, 'prices'])->whereNumber('pack');
        Route::put('freelance-plans/packs/{pack}/prices/{country}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceOfferPackController::class, 'setPrice'])->whereNumber(['pack', 'country']);
        Route::put('freelance-plans/{plan}', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'update'])->whereNumber('plan');
        Route::get('freelance-plans/{plan}/prices', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'prices'])->whereNumber('plan');
        Route::put('freelance-plans/{plan}/prices/{country}', [\Modules\Freelance\Http\Controllers\Admin\FreelancePlanController::class, 'setPrice'])->whereNumber(['plan', 'country']);
        // FL-L04: ready-made services - the review queue, one service with its images, the decision, and the orders on services.
        Route::get('freelance-services', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'index']);
        Route::get('freelance-services/orders', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'orders']);
        Route::get('freelance-services/subscriptions', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'subscriptions']);
        Route::get('freelance-services/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'show'])->whereUuid('uuid');
        Route::get('freelance-services/{uuid}/images/{media}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'image'])->whereUuid('uuid')->whereNumber('media');
        Route::post('freelance-services/{uuid}/approve', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'approve'])->whereUuid('uuid');
        Route::post('freelance-services/{uuid}/reject', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'reject'])->whereUuid('uuid');
        Route::post('freelance-services/{uuid}/suspend', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'suspend'])->whereUuid('uuid');
        Route::post('freelance-services/{uuid}/unsuspend', [\Modules\Freelance\Http\Controllers\Admin\FreelanceServiceAdminController::class, 'unsuspend'])->whereUuid('uuid');
        // FL-L09: special offers to past clients, read-only.
        Route::get('freelance-special-offers', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSpecialOfferAdminController::class, 'index']);
        Route::get('freelance-special-offers/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceSpecialOfferAdminController::class, 'show'])->whereUuid('uuid');
        // FL-L09 (part B): the loyalty programs freelancers run, read-only.
        Route::get('freelance-loyalty', [\Modules\Freelance\Http\Controllers\Admin\FreelanceLoyaltyAdminController::class, 'index']);
        // FL-L13: ownership and licences - the numbers, the standard text of each model per country, and the reports of works someone says are theirs.
        Route::get('freelance-rights/overview', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'overview']);
        Route::get('freelance-rights/terms', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'terms']);
        Route::put('freelance-rights/terms', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'saveTerms']);
        Route::delete('freelance-rights/terms/{id}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'resetTerms'])->whereNumber('id');
        Route::get('freelance-rights/claims', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'claims']);
        Route::get('freelance-rights/claims/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'claim'])->whereUuid('uuid');
        Route::post('freelance-rights/claims/{uuid}/decide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRightsAdminController::class, 'decide'])->whereUuid('uuid');
        // The promo banners on the freelancer app's home.
        Route::get('freelance-promos', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'index']);
        Route::post('freelance-promos', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'store']);
        Route::get('freelance-promos/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'show'])->whereUuid('uuid');
        Route::post('freelance-promos/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'update'])->whereUuid('uuid');
        Route::post('freelance-promos/{uuid}/toggle', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'toggle'])->whereUuid('uuid');
        Route::delete('freelance-promos/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelancePromoAdminController::class, 'destroy'])->whereUuid('uuid');

        // FL-L12: the professional community - numbers, reports, posts, hiding and mutes.
        Route::get('freelance-community/overview', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'overview']);
        Route::get('freelance-community/reports', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'reports']);
        Route::get('freelance-community/reports/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'report'])->whereUuid('uuid');
        Route::post('freelance-community/reports/{uuid}/decide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'decide'])->whereUuid('uuid');
        Route::get('freelance-community/posts', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'posts']);
        Route::get('freelance-community/posts/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'post'])->whereUuid('uuid');
        Route::post('freelance-community/{type}/{uuid}/hide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'hide'])->whereIn('type', ['posts', 'comments'])->whereUuid('uuid');
        Route::post('freelance-community/{type}/{uuid}/unhide', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'unhide'])->whereIn('type', ['posts', 'comments'])->whereUuid('uuid');
        Route::get('freelance-community/mutes', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'mutes']);
        Route::post('freelance-community/mutes', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'mute']);
        Route::delete('freelance-community/mutes/{id}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceCommunityAdminController::class, 'lift'])->whereNumber('id');
        // FL-L07: paid consultations - every booking (complaints first), one, and deciding a complaint.
        Route::get('freelance-consultation-offers', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'offers']);
        Route::get('freelance-consultation-offers/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'offer'])->whereUuid('uuid');
        Route::post('freelance-consultation-offers/{uuid}/approve', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'approve'])->whereUuid('uuid');
        Route::post('freelance-consultation-offers/{uuid}/reject', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'reject'])->whereUuid('uuid');
        Route::post('freelance-consultation-offers/{uuid}/suspend', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'suspend'])->whereUuid('uuid');
        Route::post('freelance-consultation-offers/{uuid}/unsuspend', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'unsuspend'])->whereUuid('uuid');
        Route::get('freelance-consultations', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'index']);
        Route::get('freelance-consultations/{uuid}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'show'])->whereUuid('uuid');
        Route::post('freelance-consultations/{uuid}/resolve', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'resolve'])->whereUuid('uuid');
        // FL-L16: the video session - attendance, recordings (watched in the page, every viewing written down), closing / opening the room.
        Route::get('freelance-consultations/{uuid}/session', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'session'])->whereUuid('uuid');
        Route::post('freelance-consultations/{uuid}/session/{action}', [\Modules\Freelance\Http\Controllers\Admin\FreelanceConsultationAdminController::class, 'sessionAction'])->whereUuid('uuid')->whereIn('action', ['close', 'reopen']);
        Route::get('freelance-risk/overview',[\Modules\Freelance\Http\Controllers\Admin\FreelanceRiskController::class, 'overview']);
        Route::get('freelance-risk/accounts', [\Modules\Freelance\Http\Controllers\Admin\FreelanceRiskController::class, 'accounts']);
        // FS-208: the freelancers' teams - find, open, suspend / bring back, take a member out.
        $teamsAdmin = \Modules\Freelance\Http\Controllers\Admin\FreelanceTeamAdminController::class;
        Route::get('freelance-teams/options', [$teamsAdmin, 'options']);
        Route::get('freelance-teams', [$teamsAdmin, 'index']);
        Route::get('freelance-teams/{uuid}', [$teamsAdmin, 'show'])->whereUuid('uuid');
        Route::post('freelance-teams/{uuid}/status', [$teamsAdmin, 'status'])->whereUuid('uuid');
        Route::post('freelance-teams/{uuid}/members/{freelancer}/remove', [$teamsAdmin, 'removeMember'])->whereUuid(['uuid', 'freelancer']);
        // FS-202b: the team's verification - open a paper, approve or reject the request, take the badge back.
        Route::get('freelance-teams/{uuid}/verification/documents/{document}', [$teamsAdmin, 'verificationFile'])->whereUuid(['uuid', 'document']);
        Route::post('freelance-teams/{uuid}/verification/decide', [$teamsAdmin, 'verificationDecide'])->whereUuid('uuid');
        Route::post('freelance-teams/{uuid}/verification/revoke', [$teamsAdmin, 'verificationRevoke'])->whereUuid('uuid');
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
        // FS-150d: the team's internal chat about the disputed project, read only, while the dispute is being decided (its own permission, audited).
        // FS-150f: the shared Workroom of the disputed project, read only (audited).
        Route::get('freelance-disputes/{uuid}/workroom', [\Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeWorkroomController::class, 'show'])->whereUuid('uuid');
        Route::get('freelance-disputes/{uuid}/workroom/timeline', [\Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeWorkroomController::class, 'timeline'])->whereUuid('uuid');
        Route::get('freelance-disputes/{uuid}/workroom/files/{file}/versions/{number}/download', [\Modules\Freelance\Http\Controllers\Admin\FreelanceDisputeWorkroomController::class, 'download'])->whereUuid(['uuid', 'file'])->whereNumber('number');
        Route::get('freelance-disputes/{uuid}/internal-chat', [\Modules\Freelance\Http\Controllers\Admin\FreelanceInternalChatController::class, 'show'])->whereUuid('uuid');

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
        // FS-605: the quality desk - verified works (FS-601), practical challenges (FS-602), reputation corrections (FS-604).
        $quality = \Modules\Freelance\Http\Controllers\Admin\FreelanceQualityController::class;
        Route::get('freelance-quality/summary', [$quality, 'summary']);
        Route::get('freelance-quality/verified-works', [$quality, 'works']);
        Route::post('freelance-quality/verified-works/{item}/unverify', [$quality, 'unverify'])->whereNumber('item');
        Route::post('freelance-quality/verified-works/{item}/reverify', [$quality, 'reverify'])->whereNumber('item');
        Route::get('freelance-quality/challenges', [$quality, 'challenges']);
        Route::post('freelance-quality/challenges/{uuid}/decide', [$quality, 'decideChallenge'])->whereUuid('uuid');
        Route::get('freelance-quality/corrections', [$quality, 'corrections']);
        Route::post('freelance-quality/corrections/{uuid}/decide', [$quality, 'decideCorrection'])->whereUuid('uuid');

        // Skill tests (FL-L08): per skill the settings and the question bank, plus the badges earned.
        Route::get('freelance-skill-tests', [FreelanceSkillTestController::class, 'index']);
        Route::get('freelance-skill-tests/{freelance_skill}', [FreelanceSkillTestController::class, 'show'])->whereNumber('freelance_skill');
        Route::put('freelance-skill-tests/{freelance_skill}', [FreelanceSkillTestController::class, 'update'])->whereNumber('freelance_skill');
        Route::post('freelance-skill-tests/{freelance_skill}/questions', [FreelanceSkillTestController::class, 'storeQuestion'])->whereNumber('freelance_skill');
        Route::put('freelance-skill-questions/{question}', [FreelanceSkillTestController::class, 'updateQuestion'])->whereNumber('question');
        Route::delete('freelance-skill-questions/{question}', [FreelanceSkillTestController::class, 'destroyQuestion'])->whereNumber('question');
        Route::get('freelance-skill-badges', [FreelanceSkillTestController::class, 'badges']);
        Route::post('freelance-skill-badges/{badge}/revoke', [FreelanceSkillTestController::class, 'revoke'])->whereNumber('badge');

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
