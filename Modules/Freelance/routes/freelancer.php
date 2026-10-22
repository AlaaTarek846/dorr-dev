<?php

use Illuminate\Support\Facades\Route;
use Modules\Freelance\Http\Controllers\FreelanceCatalogController;
use Modules\Freelance\Http\Controllers\FreelanceChatController;
use Modules\Freelance\Http\Controllers\FreelanceConfigController;
use Modules\Freelance\Http\Controllers\FreelanceRealtimeController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAccountController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAuthController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerAvailabilityController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerPortfolioController;
use Modules\Freelance\Http\Controllers\Freelancer\FreelancerOfferController;
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
            // Offers (FL-301), behind the `bidding` switch too.
            Route::middleware('freelance.feature:bidding')->group(function () {
                Route::get('{uuid}/offer-preview', [FreelancerOfferController::class, 'preview'])->whereUuid('uuid');
                Route::get('{uuid}/my-offer', [FreelancerOfferController::class, 'mine'])->whereUuid('uuid');
                Route::post('{uuid}/offers', [FreelancerOfferController::class, 'store'])->whereUuid('uuid')->middleware('throttle:20,1,freelance-offer-send');
            });
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
            Route::delete('messages/{message}', [FreelanceChatController::class, 'destroy'])->whereNumber('message');
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
