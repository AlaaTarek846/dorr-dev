<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\Client\ClientInvitationController;
use Modules\Freelance\Http\Controllers\ReceiptController;
use Modules\Freelance\Http\Controllers\ApprovalTrailController;
use Modules\Freelance\Http\Controllers\Client\ClientAgreementController;
use Modules\Freelance\Http\Controllers\ChangeRequestController;
use Modules\Freelance\Http\Controllers\DisputeController;
use Modules\Freelance\Http\Controllers\ReviewController;
use Modules\Freelance\Http\Controllers\Client\ClientFavoriteController;
use Modules\Freelance\Http\Controllers\Client\ClientProjectController;
use Modules\Freelance\Http\Controllers\FreelanceCatalogController;
use Modules\Freelance\Http\Controllers\FreelanceChatController;
use Modules\Freelance\Http\Controllers\MilestoneDeliveryController;
use Modules\Freelance\Http\Controllers\FreelanceInterviewController;
use Modules\Freelance\Http\Controllers\FreelanceConfigController;
use Modules\Freelance\Http\Controllers\PortfolioViewController;

// The client of freelance work is a regular Dorr user, on the Dorr user app (FL-004): their own
// guard, the same Services as the freelancer's API.
Route::middleware('locale')->prefix('mobile/v1/freelance')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:60,1', 'remember-locale'])->group(function () {
        Route::get('config', [FreelanceConfigController::class, 'show']);
        Route::get('freelancers/{uuid}/portfolio', [PortfolioViewController::class, 'show'])->whereUuid('uuid')->middleware('freelance.feature:freelance');

        // The catalogs the apps pick from (FL-101): the specialty tree, in the request's language.
        Route::get('specialties', [FreelanceCatalogController::class, 'specialties'])->middleware('freelance.feature:freelance');
        Route::get('skills', [FreelanceCatalogController::class, 'skills'])->middleware('freelance.feature:freelance');

        // The client's projects (FL-201 / FL-202): drafts, files, sending for review, taking back, cancelling.
        Route::prefix('projects')->middleware('freelance.feature:projects')->group(function () {
            Route::get('/', [ClientProjectController::class, 'index']);
            Route::post('/', [ClientProjectController::class, 'store'])->middleware('throttle:30,1,freelance-project-create');
            Route::get('budget-rules', [ClientProjectController::class, 'budgetRules']);
            Route::get('{uuid}', [ClientProjectController::class, 'show'])->whereUuid('uuid');
            Route::put('{uuid}', [ClientProjectController::class, 'update'])->whereUuid('uuid');
            Route::delete('{uuid}', [ClientProjectController::class, 'destroy'])->whereUuid('uuid');
            Route::post('{uuid}/submit', [ClientProjectController::class, 'submit'])->whereUuid('uuid');
            Route::post('{uuid}/withdraw', [ClientProjectController::class, 'withdraw'])->whereUuid('uuid');
            // FL-205: invitations to a private project.
            Route::middleware('freelance.feature:invitations')->group(function () {
                Route::get('{uuid}/invitations', [ClientInvitationController::class, 'index'])->whereUuid('uuid');
                Route::post('{uuid}/invitations', [ClientInvitationController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-invite');
                Route::delete('{uuid}/invitations/{invitation}', [ClientInvitationController::class, 'destroy'])->whereUuid(['uuid', 'invitation']);
                Route::get('{uuid}/invitees', [ClientInvitationController::class, 'invitees'])->whereUuid('uuid');
                Route::post('{uuid}/make-public', [ClientInvitationController::class, 'makePublic'])->whereUuid('uuid');
            });
            // FL-401: the agreement with the chosen freelancer.
            Route::get('{uuid}/agreement', [ClientAgreementController::class, 'show'])->whereUuid('uuid');
            Route::put('{uuid}/agreement', [ClientAgreementController::class, 'update'])->whereUuid('uuid');
            Route::put('{uuid}/agreement/milestones', [ClientAgreementController::class, 'milestones'])->whereUuid('uuid');
            Route::post('{uuid}/milestones/{milestone}/fund', [ClientAgreementController::class, 'milestoneAction'])->whereUuid(['uuid', 'milestone'])->defaults('action', 'fund')
                ->middleware(['freelance.feature:wallet_payment', 'throttle:20,1,freelance-milestone-fund']);
            // FL-601 / FL-602: the versions delivered (files kept as uploaded, downloaded by the two people of the agreement only).
            Route::get('{uuid}/milestones/{milestone}/deliveries', [MilestoneDeliveryController::class, 'index'])->whereUuid(['uuid', 'milestone']);
            Route::get('{uuid}/milestones/{milestone}/deliveries/{delivery}/files/{media}', [MilestoneDeliveryController::class, 'file'])->whereUuid(['uuid', 'milestone', 'delivery'])->whereNumber('media');
            // FL-604: what the client checks before approving.
            Route::put('{uuid}/milestones/{milestone}/checklist', [MilestoneDeliveryController::class, 'setChecklist'])->whereUuid(['uuid', 'milestone']);
            Route::post('{uuid}/milestones/{milestone}/checklist/{item}/{state}', [MilestoneDeliveryController::class, 'tick'])->whereUuid(['uuid', 'milestone', 'item'])->whereIn('state', ['check', 'uncheck']);
            Route::post('{uuid}/milestones/{milestone}/{action}', [ClientAgreementController::class, 'milestoneAction'])->whereUuid(['uuid', 'milestone'])->whereIn('action', ['approve', 'revision', 'cancel', 'cancel-accept', 'cancel-reject']);
            // FL-704: disputes on a part.
            Route::post('{uuid}/milestones/{milestone}/dispute', [DisputeController::class, 'open'])->whereUuid(['uuid', 'milestone'])->middleware('freelance.feature:disputes');
            Route::get('{uuid}/disputes/{dispute}', [DisputeController::class, 'show'])->whereUuid(['uuid', 'dispute']);
            Route::post('{uuid}/disputes/{dispute}/evidence', [DisputeController::class, 'evidence'])->whereUuid(['uuid', 'dispute'])->middleware('throttle:20,1,freelance-dispute-evidence');
            Route::post('{uuid}/disputes/{dispute}/appeal', [DisputeController::class, 'appeal'])->whereUuid(['uuid', 'dispute']);
            Route::get('{uuid}/disputes/{dispute}/files/{media}', [DisputeController::class, 'file'])->whereUuid(['uuid', 'dispute'])->whereNumber('media');
            // FL-701: rating each other after the project.
            Route::get('{uuid}/review', [ReviewController::class, 'show'])->whereUuid('uuid');
            Route::post('{uuid}/review', [ReviewController::class, 'store'])->whereUuid('uuid')->middleware('freelance.feature:ratings');
            // FL-404 / FL-405: changes to an active agreement.
            // FL-406: the record of approvals.
            Route::get('{uuid}/agreement/trail', [ApprovalTrailController::class, 'index'])->whereUuid('uuid');
            Route::get('{uuid}/agreement/changes', [ChangeRequestController::class, 'index'])->whereUuid('uuid');
            Route::post('{uuid}/agreement/changes', [ChangeRequestController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-change');
            Route::post('{uuid}/agreement/changes/{change}/{action}', [ChangeRequestController::class, 'answer'])->whereUuid(['uuid', 'change'])->whereIn('action', ['accept', 'reject', 'withdraw']);
            Route::post('{uuid}/agreement/send', [ClientAgreementController::class, 'send'])->whereUuid('uuid');
            Route::post('{uuid}/agreement/cancel', [ClientAgreementController::class, 'cancel'])->whereUuid('uuid');
            Route::get('{uuid}/offers', [ClientProjectController::class, 'offers'])->whereUuid('uuid');
            Route::get('{uuid}/offers/compare', [ClientProjectController::class, 'compareOffers'])->whereUuid('uuid');
            Route::get('{uuid}/offers/{offer}', [ClientProjectController::class, 'showOffer'])->whereUuid(['uuid', 'offer']);
            Route::post('{uuid}/offers/{offer}/chat', [ClientProjectController::class, 'openChat'])->whereUuid(['uuid', 'offer'])->middleware('freelance.feature:chat');
            Route::get('{uuid}/offers/{offer}/freelancer', [ClientProjectController::class, 'offerFreelancer'])->whereUuid(['uuid', 'offer']);
            Route::post('{uuid}/offers/{offer}/{action}', [ClientProjectController::class, 'decideOffer'])->whereUuid(['uuid', 'offer'])->whereIn('action', ['shortlist', 'unshortlist', 'reject', 'choose']);
            Route::get('{uuid}/offers/{offer}/attachments/{media}', [ClientProjectController::class, 'offerAttachment'])->whereUuid(['uuid', 'offer'])->whereNumber('media');
            Route::post('{uuid}/offers/close', [ClientProjectController::class, 'closeOffers'])->whereUuid('uuid');
            Route::post('{uuid}/offers/reopen', [ClientProjectController::class, 'reopenOffers'])->whereUuid('uuid');
            Route::post('{uuid}/cancel', [ClientProjectController::class, 'cancel'])->whereUuid('uuid');
            Route::post('{uuid}/attachments', [ClientProjectController::class, 'addAttachment'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-project-upload');
            Route::get('{uuid}/attachments/{media}', [ClientProjectController::class, 'attachment'])->whereUuid('uuid')->whereNumber('media');
            Route::delete('{uuid}/attachments/{media}', [ClientProjectController::class, 'removeAttachment'])->whereUuid('uuid')->whereNumber('media');
        });

        // The freelancers I keep (FL-306).
        // FL-511: my receipts of the project money.
        Route::get('receipts', [ReceiptController::class, 'index'])->middleware('freelance.feature:projects');
        Route::get('receipts/{uuid}', [ReceiptController::class, 'show'])->whereUuid('uuid')->middleware('freelance.feature:projects');

        Route::prefix('favorites')->middleware('freelance.feature:projects')->group(function () {
            Route::get('/', [ClientFavoriteController::class, 'index']);
            Route::post('{uuid}', [ClientFavoriteController::class, 'store'])->whereUuid('uuid');
            Route::delete('{uuid}', [ClientFavoriteController::class, 'destroy'])->whereUuid('uuid');
        });

        // The chat with the freelancer of a project (FL-006): its own chat, not Modules/Chat.
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
        });
    });
});

// A portfolio image link: signed, and it expires (FL-105). No sign-in, so it opens in an image view.
Route::get('mobile/v1/freelance/portfolio-images/{media}', [PortfolioViewController::class, 'image'])->whereNumber('media')->middleware(['signed', 'throttle:120,1,freelance-portfolio-image'])->name('freelance.portfolio.image'); // the module's routes are named `api.` + this
