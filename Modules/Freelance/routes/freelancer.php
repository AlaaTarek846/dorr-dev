<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerInvitationController;
use Modules\Freelance\Http\Controllers\ReceiptController;
use Modules\Freelance\Http\Controllers\ApprovalTrailController;
use Modules\Freelance\Http\Controllers\FreelanceCatalogController;
use Modules\Freelance\Http\Controllers\FreelanceChatController;
use Modules\Freelance\Http\Controllers\MilestoneDeliveryController;
use Modules\Freelance\Http\Controllers\FreelanceInterviewController;
use Modules\Freelance\Http\Controllers\ProgressReportController;
use Modules\Freelance\Http\Controllers\FreelanceConfigController;
use Modules\Freelance\Http\Controllers\FreelanceLegalPageController;
use Modules\Freelance\Http\Controllers\FreelanceRealtimeController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAccountController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAuthController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAppearanceController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerMyProjectsController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerRatingController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerReferralController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerSecurityController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerSupportController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAvailabilityController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerPortfolioController;
use Modules\Freelance\Http\Controllers\ChangeRequestController;
use Modules\Freelance\Http\Controllers\DisputeController;
use Modules\Freelance\Http\Controllers\ReviewController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAgreementController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerOfferController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerOfferTemplateController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerProjectController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerProfileListsController;
use Modules\Freelance\Http\Controllers\PortfolioViewController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerVerificationController;

// The freelancer: one API for the app, the website and the dashboard (FL-003, FL-004).
Route::middleware('locale')->prefix('freelance/v1')->group(function () {
    Route::middleware(['guest:freelance_api', 'throttle:10,1'])->group(function () {
        Route::post('auth/otp', [FreelancerAuthController::class, 'requestOtp']);
        Route::post('auth/verify', [FreelancerAuthController::class, 'verifyOtp']);
        Route::post('auth/resend', [FreelancerAuthController::class, 'resendOtp']);
    });

    // The Freelance service's privacy policy and terms, read before sign-in too (falls back to the general pages).
    Route::get('legal-pages', [FreelanceLegalPageController::class, 'show'])->middleware('throttle:60,1');

    // Anyone may read a verified freelancer's public works (the website).
    Route::get('public/freelancers/{uuid}/portfolio', [PortfolioViewController::class, 'show'])->whereUuid('uuid')->middleware('throttle:60,1,freelance-public-portfolio');

    Route::middleware(['auth:freelance_api', 'ensure-phone-verified:freelance_api', 'throttle:60,1', 'remember-locale'])->group(function () {
        Route::get('config', [FreelanceConfigController::class, 'show']);

        // The catalogs the apps pick from (FL-101): the specialty tree, in the request's language.
        Route::get('specialties', [FreelanceCatalogController::class, 'specialties'])->middleware('freelance.feature:freelance');
        Route::get('skills', [FreelanceCatalogController::class, 'skills'])->middleware('freelance.feature:freelance');
        Route::post('skills/suggest', [FreelanceCatalogController::class, 'suggestSkill'])->middleware(['freelance.feature:freelance', 'throttle:20,1,freelance-skill-suggest']);
        Route::get('realtime-config', FreelanceRealtimeController::class);
        Route::get('auth/me', [FreelancerAuthController::class, 'me']);
        Route::post('auth/logout', [FreelancerAuthController::class, 'logout']);

        // The app's colours, dark mode and font, as in the DORR app's settings.
        Route::get('appearance', [FreelancerAppearanceController::class, 'show']);
        Route::put('appearance', [FreelancerAppearanceController::class, 'update']);

        // Rate the app, the FAQs and support (the guided help, tickets and their conversations) - as in the DORR app.
        Route::get('ratings/mine', [FreelancerRatingController::class, 'mine']);
        Route::post('ratings', [FreelancerRatingController::class, 'store'])->middleware('throttle:10,1');
        Route::get('faqs', [\Modules\User\Http\Controllers\Mobile\FaqController::class, 'index']);
        Route::get('support-help', [FreelancerSupportController::class, 'help']);
        Route::post('support-help/{node}/feedback', [FreelancerSupportController::class, 'helpFeedback'])->whereNumber('node')->middleware('throttle:30,1');
        Route::get('support-tickets', [FreelancerSupportController::class, 'index']);
        Route::post('support-tickets', [FreelancerSupportController::class, 'store'])->middleware('throttle:10,1');
        Route::get('support-tickets/{ticket}', [FreelancerSupportController::class, 'show'])->whereNumber('ticket');
        Route::patch('support-tickets/{ticket}/status', [FreelancerSupportController::class, 'status'])->whereNumber('ticket');
        Route::get('support-tickets/{ticket}/messages', [FreelancerSupportController::class, 'messages'])->whereNumber('ticket');
        Route::post('support-tickets/{ticket}/messages', [FreelancerSupportController::class, 'send'])->whereNumber('ticket')->middleware('throttle:30,1');
        Route::post('support-tickets/{ticket}/auto-reply-feedback', [FreelancerSupportController::class, 'autoReplyFeedback'])->whereNumber('ticket')->middleware('throttle:30,1');

        Route::put('profile', [FreelancerAccountController::class, 'updateProfile']);
        Route::post('profile/email/send-code', [FreelancerAccountController::class, 'sendEmailCode'])->middleware('throttle:5,1,freelancer-email-code');
        Route::post('profile/email/confirm', [FreelancerAccountController::class, 'confirmEmail'])->middleware('throttle:10,1,freelancer-email-confirm');
        // Specialties, skills, work history and certificates (FL-104).
        Route::get('profile/specialties', [FreelancerProfileListsController::class, 'specialties']);
        Route::put('profile/specialties', [FreelancerProfileListsController::class, 'setSpecialties']);
        Route::get('profile/skills', [FreelancerProfileListsController::class, 'skills']);
        Route::put('profile/skills', [FreelancerProfileListsController::class, 'setSkills']);
        Route::get('profile/experiences', [FreelancerProfileListsController::class, 'experiences']);
        Route::post('profile/experiences', [FreelancerProfileListsController::class, 'storeExperience']);
        Route::put('profile/experiences/{experience}', [FreelancerProfileListsController::class, 'updateExperience'])->whereNumber('experience');
        Route::delete('profile/experiences/{experience}', [FreelancerProfileListsController::class, 'destroyExperience'])->whereNumber('experience');
        Route::get('profile/certificates', [FreelancerProfileListsController::class, 'certificates']);
        Route::get('profile/certificates/{certificate}/proof', [FreelancerProfileListsController::class, 'proof'])->whereNumber('certificate');
        Route::delete('profile/certificates/{certificate}', [FreelancerProfileListsController::class, 'destroyCertificate'])->whereNumber('certificate');
        // Browsing the clients' projects (FL-206 / FL-207): published, public, of the freelancer's own country.
        Route::prefix('projects')->middleware('freelance.feature:projects')->group(function () {
            Route::get('/', [FreelancerProjectController::class, 'index']);
            Route::get('{uuid}', [FreelancerProjectController::class, 'show'])->whereUuid('uuid');
            Route::post('{uuid}/favorite', [FreelancerProjectController::class, 'favorite'])->whereUuid('uuid');
            Route::delete('{uuid}/favorite', [FreelancerProjectController::class, 'unfavorite'])->whereUuid('uuid');
            // FL-401: the agreement on my chosen offer.
            Route::get('{uuid}/agreement', [FreelancerAgreementController::class, 'show'])->whereUuid('uuid');
            Route::post('{uuid}/agreement/approve', [FreelancerAgreementController::class, 'approve'])->whereUuid('uuid');
            // FL-601 / FL-602: the versions delivered (files kept as uploaded, downloaded by the two people of the agreement only).
            Route::get('{uuid}/milestones/{milestone}/deliveries', [MilestoneDeliveryController::class, 'index'])->whereUuid(['uuid', 'milestone']);
            Route::get('{uuid}/milestones/{milestone}/deliveries/{delivery}/files/{media}', [MilestoneDeliveryController::class, 'file'])->whereUuid(['uuid', 'milestone', 'delivery'])->whereNumber('media');
            Route::post('{uuid}/milestones/{milestone}/{action}', [FreelancerAgreementController::class, 'milestoneAction'])->whereUuid(['uuid', 'milestone'])->whereIn('action', ['start', 'deliver', 'withdraw', 'cancel-accept', 'cancel-reject']);
            // FL-704: disputes on a part.
            Route::post('{uuid}/milestones/{milestone}/dispute', [DisputeController::class, 'open'])->whereUuid(['uuid', 'milestone'])->middleware('freelance.feature:disputes');
            Route::get('{uuid}/disputes/{dispute}', [DisputeController::class, 'show'])->whereUuid(['uuid', 'dispute']);
            Route::post('{uuid}/disputes/{dispute}/evidence', [DisputeController::class, 'evidence'])->whereUuid(['uuid', 'dispute'])->middleware('throttle:20,1,freelance-dispute-evidence');
            Route::post('{uuid}/disputes/{dispute}/appeal', [DisputeController::class, 'appeal'])->whereUuid(['uuid', 'dispute']);
            Route::get('{uuid}/disputes/{dispute}/files/{media}', [DisputeController::class, 'file'])->whereUuid(['uuid', 'dispute'])->whereNumber('media');
            // FL-701: rating each other after the project.
            Route::get('{uuid}/review', [ReviewController::class, 'show'])->whereUuid('uuid');
            Route::post('{uuid}/review', [ReviewController::class, 'store'])->whereUuid('uuid')->middleware('freelance.feature:ratings');
            // FL-706: object to the rating about me. FL-702: the short satisfaction survey.
            Route::post('{uuid}/review/objection', [ReviewController::class, 'object'])->whereUuid('uuid')->middleware(['freelance.feature:ratings', 'throttle:10,1,freelance-objection']);
            Route::get('{uuid}/survey', [ReviewController::class, 'survey'])->whereUuid('uuid');
            Route::post('{uuid}/survey', [ReviewController::class, 'answerSurvey'])->whereUuid('uuid')->middleware('throttle:10,1,freelance-survey');
            // FL-404 / FL-405: changes to an active agreement.
            // FL-406: the record of approvals.
            Route::get('{uuid}/agreement/trail', [ApprovalTrailController::class, 'index'])->whereUuid('uuid');
            Route::get('{uuid}/agreement/changes', [ChangeRequestController::class, 'index'])->whereUuid('uuid');
            // FL-605: optional progress reports (the freelancer sends, the two read).
            Route::get('{uuid}/reports', [ProgressReportController::class, 'index'])->whereUuid('uuid');
            Route::post('{uuid}/reports', [ProgressReportController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-report');
            Route::post('{uuid}/agreement/changes', [ChangeRequestController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-change');
            Route::post('{uuid}/agreement/changes/{change}/{action}', [ChangeRequestController::class, 'answer'])->whereUuid(['uuid', 'change'])->whereIn('action', ['accept', 'reject', 'withdraw']);
            Route::post('{uuid}/agreement/decline', [FreelancerAgreementController::class, 'decline'])->whereUuid('uuid');
            // Offers (FL-301), behind the `bidding` switch too.
            Route::middleware('freelance.feature:bidding')->group(function () {
                Route::post('{uuid}/offer-check', [FreelancerOfferTemplateController::class, 'check'])->whereUuid('uuid');
                Route::get('{uuid}/offer-preview', [FreelancerOfferController::class, 'preview'])->whereUuid('uuid');
                Route::get('{uuid}/my-offer', [FreelancerOfferController::class, 'mine'])->whereUuid('uuid');
                Route::post('{uuid}/offers', [FreelancerOfferController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-offer-send');
            });
        });
        // FL-205: my invitations to private projects; answering needs a verified account (FL-110).
        Route::prefix('invitations')->middleware(['freelance.feature:projects', 'freelance.feature:invitations'])->group(function () {
            Route::get('/', [FreelancerInvitationController::class, 'index']);
            Route::post('{uuid}/{action}', [FreelancerInvitationController::class, 'respond'])->whereUuid('uuid')->whereIn('action', ['accept', 'decline'])->middleware('freelance.verified');
        });
        // FL-703: my numbers and the latest ratings clients gave me.
        Route::get('reviews', [ReviewController::class, 'mine']);
        // FL-708: my warnings, limits and suspensions, and appealing one.
        Route::get('sanctions', [\Modules\Freelance\Http\Controllers\SanctionController::class, 'index']);
        Route::post('sanctions/{uuid}/appeal', [\Modules\Freelance\Http\Controllers\SanctionController::class, 'appeal'])->whereUuid('uuid')->middleware('throttle:10,1,freelance-sanction-appeal');
        // FL-511: my receipts of the project money.
        Route::get('receipts', [ReceiptController::class, 'index']);
        Route::get('receipts/{uuid}', [ReceiptController::class, 'show'])->whereUuid('uuid');

        // The projects I was chosen for: working on, finished, or ended (the app's «My work» tab).
        Route::get('my-projects', [FreelancerMyProjectsController::class, 'index'])->middleware('freelance.feature:projects');

        // FL-509: my earnings from projects.
        Route::get('earnings', [FreelancerAgreementController::class, 'earnings'])->middleware('freelance.feature:projects');

        // FL-304: my offer texts to start from.
        Route::prefix('offer-templates')->middleware(['freelance.feature:projects', 'freelance.feature:bidding'])->group(function () {
            Route::get('/', [FreelancerOfferTemplateController::class, 'index']);
            Route::post('/', [FreelancerOfferTemplateController::class, 'store']);
            Route::put('{id}', [FreelancerOfferTemplateController::class, 'update'])->whereNumber('id');
            Route::delete('{id}', [FreelancerOfferTemplateController::class, 'destroy'])->whereNumber('id');
        });

        Route::prefix('offers')->middleware(['freelance.feature:projects', 'freelance.feature:bidding'])->group(function () {
            Route::get('/', [FreelancerOfferController::class, 'index']);
            Route::put('{offer}', [FreelancerOfferController::class, 'update'])->whereUuid('offer');
            Route::post('{offer}/withdraw', [FreelancerOfferController::class, 'withdraw'])->whereUuid('offer');
            Route::get('{offer}/revisions', [FreelancerOfferController::class, 'revisions'])->whereUuid('offer');
            Route::get('{offer}/attachments/{media}', [FreelancerOfferController::class, 'attachment'])->whereUuid('offer')->whereNumber('media');
        });

        // How the freelancer can be reached: pause, guide price, weekly hours, time off (FL-106).
        Route::get('profile/availability', [FreelancerAvailabilityController::class, 'show']);
        Route::put('profile/availability', [FreelancerAvailabilityController::class, 'update']);
        Route::get('profile/time-off', [FreelancerAvailabilityController::class, 'timeOff']);
        Route::post('profile/time-off', [FreelancerAvailabilityController::class, 'addTimeOff']);
        Route::delete('profile/time-off/{period}', [FreelancerAvailabilityController::class, 'removeTimeOff'])->whereNumber('period');
        // The portfolio (FL-105).
        Route::get('profile/portfolio', [FreelancerPortfolioController::class, 'index']);
        Route::post('profile/portfolio', [FreelancerPortfolioController::class, 'store']);
        Route::put('profile/portfolio/order', [FreelancerPortfolioController::class, 'order']);
        Route::put('profile/portfolio/{item}', [FreelancerPortfolioController::class, 'update'])->whereNumber('item');
        Route::delete('profile/portfolio/{item}', [FreelancerPortfolioController::class, 'destroy'])->whereNumber('item');
        Route::get('profile/portfolio/{item}/images/{media}', [FreelancerPortfolioController::class, 'image'])->whereNumber(['item', 'media']);
        Route::delete('profile/portfolio/{item}/images/{media}', [FreelancerPortfolioController::class, 'destroyImage'])->whereNumber(['item', 'media']);

        // Verifying the account by hand (FL-107 / FL-108); the gate itself is `freelance.verified` (FL-110).
        Route::get('verification', [FreelancerVerificationController::class, 'show']);
        Route::post('verification/submit', [FreelancerVerificationController::class, 'submit'])->middleware('throttle:10,1,freelancer-verification-submit');
        Route::get('verification/documents/{document}/{side}', [FreelancerVerificationController::class, 'file'])->whereNumber('document')->whereIn('side', ['front', 'back']);
        Route::delete('verification/documents/{document}', [FreelancerVerificationController::class, 'destroy'])->whereNumber('document');
        Route::post('terms/accept', [FreelancerAccountController::class, 'acceptTerms']);
        // Settings > Security: the devices signed in (sign any out, or all but this one) and the account's security log.
        Route::get('security/devices', [FreelancerSecurityController::class, 'devices']);
        Route::post('security/devices/logout-others', [FreelancerSecurityController::class, 'signOutOthers'])->middleware('throttle:10,1');
        Route::delete('security/devices/{session}', [FreelancerSecurityController::class, 'signOut'])->whereNumber('session')->middleware('throttle:20,1');
        Route::get('security/events', [FreelancerSecurityController::class, 'events']);
        // Account > Share the app with friends: the freelancer's own invite code.
        Route::get('referrals/my-code', [FreelancerReferralController::class, 'myCode']);
        Route::get('devices', [FreelancerAccountController::class, 'devices']);
        Route::delete('devices/{device}', [FreelancerAccountController::class, 'destroyDevice'])->whereNumber('device');
        Route::delete('account', [FreelancerAccountController::class, 'destroyAccount']);

        // The chat with the client of a project (FL-006): its own chat, not Modules/Chat.
        Route::prefix('chat')->group(function () {
            Route::get('conversations', [FreelanceChatController::class, 'conversations']);
            Route::get('conversations/{uuid}', [FreelanceChatController::class, 'show'])->whereUuid('uuid');
            Route::get('conversations/{uuid}/messages', [FreelanceChatController::class, 'messages'])->whereUuid('uuid');
            Route::post('conversations/{uuid}/messages', [FreelanceChatController::class, 'send'])->whereUuid('uuid')->middleware('throttle:60,1,freelance-chat-send');
            Route::post('conversations/{uuid}/read', [FreelanceChatController::class, 'read'])->whereUuid('uuid');
            // FL-310: short interviews inside the room.
            Route::get('conversations/{uuid}/interviews', [FreelanceInterviewController::class, 'index'])->whereUuid('uuid');
            Route::post('conversations/{uuid}/interviews', [FreelanceInterviewController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-interview');
            Route::post('conversations/{uuid}/interviews/{interview}/{action}', [FreelanceInterviewController::class, 'respond'])->whereUuid(['uuid', 'interview'])->whereIn('action', ['confirm', 'decline', 'cancel']);
            Route::delete('messages/{message}', [FreelanceChatController::class, 'destroy'])->whereNumber('message');
            // FL-709: report the other person's message.
            Route::post('messages/{message}/report', [\Modules\Freelance\Http\Controllers\MessageReportController::class, 'store'])->whereNumber('message')->middleware('throttle:20,1,freelance-message-report');
        });
    });

    Route::middleware(['auth:freelance_api', 'ensure-phone-verified:freelance_api', 'throttle:20,1,freelancer-uploads'])->group(function () {
        Route::post('profile/avatar', [FreelancerAccountController::class, 'avatar']);
        Route::post('profile/certificates', [FreelancerProfileListsController::class, 'storeCertificate']);
        Route::post('verification/documents', [FreelancerVerificationController::class, 'upload']);
        Route::post('profile/portfolio/{item}/images', [FreelancerPortfolioController::class, 'addImage'])->whereNumber('item');
        Route::post('profile/certificates/{certificate}', [FreelancerProfileListsController::class, 'updateCertificate'])->whereNumber('certificate');
    });
});
