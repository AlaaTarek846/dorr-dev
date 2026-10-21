<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\Client\ClientProjectController;
use Modules\Freelance\Http\Controllers\FreelanceCatalogController;
use Modules\Freelance\Http\Controllers\FreelanceChatController;
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
            Route::get('{uuid}/offers', [ClientProjectController::class, 'offers'])->whereUuid('uuid');
            Route::get('{uuid}/offers/compare', [ClientProjectController::class, 'compareOffers'])->whereUuid('uuid');
            Route::get('{uuid}/offers/{offer}', [ClientProjectController::class, 'showOffer'])->whereUuid(['uuid', 'offer']);
            Route::get('{uuid}/offers/{offer}/freelancer', [ClientProjectController::class, 'offerFreelancer'])->whereUuid(['uuid', 'offer']);
            Route::post('{uuid}/offers/{offer}/{action}', [ClientProjectController::class, 'decideOffer'])->whereUuid(['uuid', 'offer'])->whereIn('action', ['shortlist', 'reject', 'choose']);
            Route::get('{uuid}/offers/{offer}/attachments/{media}', [ClientProjectController::class, 'offerAttachment'])->whereUuid(['uuid', 'offer'])->whereNumber('media');
            Route::post('{uuid}/offers/close', [ClientProjectController::class, 'closeOffers'])->whereUuid('uuid');
            Route::post('{uuid}/offers/reopen', [ClientProjectController::class, 'reopenOffers'])->whereUuid('uuid');
            Route::post('{uuid}/cancel', [ClientProjectController::class, 'cancel'])->whereUuid('uuid');
            Route::post('{uuid}/attachments', [ClientProjectController::class, 'addAttachment'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-project-upload');
            Route::get('{uuid}/attachments/{media}', [ClientProjectController::class, 'attachment'])->whereUuid('uuid')->whereNumber('media');
            Route::delete('{uuid}/attachments/{media}', [ClientProjectController::class, 'removeAttachment'])->whereUuid('uuid')->whereNumber('media');
        });

        // The chat with the freelancer of a project (FL-006): its own chat, not Modules/Chat.
        Route::prefix('chat')->group(function () {
            Route::get('conversations', [FreelanceChatController::class, 'conversations']);
            Route::get('conversations/{uuid}', [FreelanceChatController::class, 'show'])->whereUuid('uuid');
            Route::get('conversations/{uuid}/messages', [FreelanceChatController::class, 'messages'])->whereUuid('uuid');
            Route::post('conversations/{uuid}/messages', [FreelanceChatController::class, 'send'])->whereUuid('uuid')->middleware('throttle:60,1,freelance-chat-send');
            Route::post('conversations/{uuid}/read', [FreelanceChatController::class, 'read'])->whereUuid('uuid');
            Route::delete('messages/{message}', [FreelanceChatController::class, 'destroy'])->whereNumber('message');
        });
    });
});

// A portfolio image link: signed, and it expires (FL-105). No sign-in, so it opens in an image view.
Route::get('mobile/v1/freelance/portfolio-images/{media}', [PortfolioViewController::class, 'image'])->whereNumber('media')->middleware(['signed', 'throttle:120,1,freelance-portfolio-image'])->name('freelance.portfolio.image'); // the module's routes are named `api.` + this
