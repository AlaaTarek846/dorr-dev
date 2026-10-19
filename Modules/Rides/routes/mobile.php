<?php

use Illuminate\Support\Facades\Route;
use Modules\Rides\Http\Controllers\Cases\PartyCaseController;
use Modules\Rides\Http\Controllers\RideChangeController;
use Modules\Rides\Http\Controllers\RideSafetyController;
use Modules\Rides\Http\Controllers\SafetyCenterController;
use Modules\Rides\Http\Controllers\Rider\CircleController;
use Modules\Rides\Http\Controllers\Rider\PlacesController;
use Modules\Rides\Http\Controllers\Rider\RideController;
use Modules\Rides\Http\Controllers\Rider\RideFeaturesController;

// The rider side, inside the DORR app (docs/rides-plan.md §15).
Route::middleware('locale')->prefix('mobile/v1/rides')->group(function () {
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:120,1', 'remember-locale'])->group(function () {
        // What is switched on where the rider is: the whole service, each category, each feature (§5).
        Route::get('features', [RideFeaturesController::class, 'index']);
        // O-22, O-23: bus booking — find a trip, its seats, book; my tickets.
        Route::get('bus/trips', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'search']);
        Route::get('bus/trips/{uuid}', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'show'])->whereUuid('uuid');
        Route::post('bus/trips/{uuid}/book', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'book'])->whereUuid('uuid');
        Route::get('bus/bookings', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'bookings']);
        Route::get('bus/bookings/{uuid}', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'booking'])->whereUuid('uuid');
        Route::post('bus/bookings/{uuid}/cancel', [\Modules\Rides\Http\Controllers\Bus\RiderBusController::class, 'cancel'])->whereUuid('uuid');
        // O-25: boats & yachts — sea trips and yachts for hire, book, my bookings, more time.
        Route::get('marine/listings', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'index']);
        Route::get('marine/listings/{uuid}', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'show'])->whereUuid('uuid');
        Route::post('marine/listings/{uuid}/book', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'book'])->whereUuid('uuid');
        Route::get('marine/bookings', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'bookings']);
        Route::get('marine/bookings/{uuid}', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'booking'])->whereUuid('uuid');
        Route::post('marine/bookings/{uuid}/cancel', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'cancel'])->whereUuid('uuid');
        Route::post('marine/bookings/{uuid}/extend', [\Modules\Rides\Http\Controllers\Marine\RiderMarineController::class, 'extend'])->whereUuid('uuid');
        // O-03: which map to draw — Google or Mapbox (the admin's choice).
        Route::get('map-config', \Modules\Rides\Http\Controllers\MapConfigController::class);
        // R-16..R-18: the approved pickup / drop-off points around a place.
        Route::get('pickup-points', [RideController::class, 'pickupPoints']);
        // R-5, R-174: Home, Work, favourite places and recent destinations.
        Route::get('places', [PlacesController::class, 'index']);
        // R-INN-07 DORR Circle: my favourite captains to choose from.
        Route::get('circle', [CircleController::class, 'index']);

        // Complaints and financial disputes, apart from general support (R-133..R-140).
        Route::get('cases/options', [PartyCaseController::class, 'options']);
        Route::get('cases', [PartyCaseController::class, 'index']);
        Route::post('cases', [PartyCaseController::class, 'store']);
        Route::get('cases/{uuid}', [PartyCaseController::class, 'show'])->whereUuid('uuid');
        Route::post('cases/{uuid}/messages', [PartyCaseController::class, 'message'])->whereUuid('uuid');
        Route::post('cases/{uuid}/files', [PartyCaseController::class, 'file'])->whereUuid('uuid');
        Route::post('cases/{uuid}/appeal', [PartyCaseController::class, 'appeal'])->whereUuid('uuid');

        Route::get('cancel-reasons', [RideController::class, 'cancelReasons']);
        Route::get('rating-tags', [RideController::class, 'ratingTags']);
        Route::get('current', [RideController::class, 'current']);
        Route::get('/', [RideController::class, 'index']);
        Route::get('{uuid}', [RideController::class, 'show'])->whereUuid('uuid');
        Route::get('{uuid}/cancel/preview', [RideController::class, 'cancelPreview'])->whereUuid('uuid');
        // The ride's chat with the driver (open while a driver is on the ride).
        Route::get('{uuid}/messages', [RideController::class, 'messages'])->whereUuid('uuid');
        Route::get('{uuid}/bids', [RideController::class, 'bids'])->whereUuid('uuid');
        Route::post('{uuid}/messages', [RideController::class, 'sendMessage'])->whereUuid('uuid');
        // Safety (R-86, R-110, R-111): the family link and SOS.
        Route::get('{uuid}/share', [RideSafetyController::class, 'shareLink'])->whereUuid('uuid');
        Route::post('{uuid}/share', [RideSafetyController::class, 'share'])->whereUuid('uuid');
        Route::delete('{uuid}/share', [RideSafetyController::class, 'unshare'])->whereUuid('uuid');
        Route::post('{uuid}/sos', [RideSafetyController::class, 'sos'])->whereUuid('uuid');
        Route::post('{uuid}/contacts/share', [RideSafetyController::class, 'shareWithContacts'])->whereUuid('uuid');
        Route::post('{uuid}/block', [RideSafetyController::class, 'block'])->whereUuid('uuid');
        Route::post('{uuid}/favorite', [RideSafetyController::class, 'favorite'])->whereUuid('uuid');
        Route::post('{uuid}/report', [RideSafetyController::class, 'report'])->whereUuid('uuid');
        Route::post('{uuid}/vehicle-mismatch', [RideSafetyController::class, 'vehicleMismatch'])->whereUuid('uuid');
        Route::post('{uuid}/safety-checks/{check}', [RideSafetyController::class, 'answerCheck'])->whereUuid(['uuid', 'check']);
        // The Safety Center (R-219): trusted contacts, preferences, blocked, favourites.
        Route::get('safety', [SafetyCenterController::class, 'show']);
        Route::post('safety/contacts', [SafetyCenterController::class, 'storeContact']);
        Route::put('safety/contacts/{id}', [SafetyCenterController::class, 'updateContact'])->whereNumber('id');
        Route::delete('safety/contacts/{id}', [SafetyCenterController::class, 'destroyContact'])->whereNumber('id');
        Route::put('safety/preferences', [SafetyCenterController::class, 'preferences']);
        Route::delete('safety/blocks/{id}', [SafetyCenterController::class, 'unblock'])->whereNumber('id');
        Route::delete('safety/favorites/{driver}', [SafetyCenterController::class, 'unfavorite'])->whereNumber('driver');
        // A new destination or one more stop during the ride (R-91..95): preview → send / cancel.
        Route::post('{uuid}/changes', [RideChangeController::class, 'preview'])->whereUuid('uuid');
        Route::post('{uuid}/changes/{change}/send', [RideChangeController::class, 'send'])->whereUuid(['uuid', 'change']);
        Route::post('{uuid}/changes/{change}/cancel', [RideChangeController::class, 'cancel'])->whereUuid(['uuid', 'change']);
    });

    // Pricing calls Google: a tighter limit.
    Route::middleware(['auth:user_api', 'ensure-phone-verified:user_api', 'throttle:30,1', 'remember-locale'])->group(function () {
        Route::post('quotes', [RideController::class, 'quotes']);
        Route::post('/', [RideController::class, 'store']);
        Route::post('{uuid}/cancel', [RideController::class, 'cancel'])->whereUuid('uuid');
        Route::post('{uuid}/rate', [RideController::class, 'rate'])->whereUuid('uuid');
        Route::post('{uuid}/tip', [RideController::class, 'tip'])->whereUuid('uuid');
        Route::post('{uuid}/payment-method', [RideController::class, 'changePayment'])->whereUuid('uuid');
        // "Search all captains", after the circle had no one (R-INN-07).
        Route::post('{uuid}/circle/widen', [CircleController::class, 'widen'])->whereUuid('uuid');

        // Bidding (§9): the offers, take / decline one, raise my price.
        Route::post('{uuid}/bids/{bid}/accept', [RideController::class, 'acceptBid'])->whereUuid(['uuid', 'bid']);
        Route::post('{uuid}/bids/{bid}/decline', [RideController::class, 'declineBid'])->whereUuid(['uuid', 'bid']);
        Route::post('{uuid}/raise', [RideController::class, 'raise'])->whereUuid('uuid');
    });
});
