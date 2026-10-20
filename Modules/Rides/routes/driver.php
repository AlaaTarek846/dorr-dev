<?php

use Illuminate\Support\Facades\Route;
use Modules\Rides\Http\Controllers\Cases\PartyCaseController;
use Modules\Rides\Http\Controllers\Driver\DriverAirportController;
use Modules\Rides\Http\Controllers\Driver\DriverDrivingHoursController;
use Modules\Rides\Http\Controllers\Driver\DriverEarningsController;
use Modules\Rides\Http\Controllers\Driver\DriverPreferencesController;
use Modules\Rides\Http\Controllers\RideChangeController;
use Modules\Rides\Http\Controllers\RideSafetyController;
use Modules\Rides\Http\Controllers\SafetyCenterController;
use Modules\Rides\Http\Controllers\Driver\DriverRealtimeController;
use Modules\Rides\Http\Controllers\Driver\DriverRegulatorController;
use Modules\Rides\Http\Controllers\Driver\DriverRideController;
use Modules\Rides\Http\Controllers\Driver\DriverScheduledController;

// The driver app's ride screens (docs/rides-plan.md §17) — same prefix and guard as Modules/Driver.
Route::middleware(['locale', 'auth:driver_api', 'ensure-phone-verified:driver_api', 'throttle:120,1', 'remember-locale'])
    ->prefix('driver/v1')->group(function () {
        // Real-time + push settings for the captain app (replaces asking the server every few seconds).
        Route::get('realtime-config', DriverRealtimeController::class);
        // O-20, O-21, O-24: the captain's bus, his trips, who booked, boarding.
        Route::get('bus/vehicle', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'vehicle']);
        Route::get('bus/trips', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'index']);
        Route::post('bus/trips', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'store']);
        Route::get('bus/trips/{uuid}', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'show'])->whereUuid('uuid');
        Route::put('bus/trips/{uuid}', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'update'])->whereUuid('uuid');
        Route::post('bus/trips/{uuid}/cancel', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'cancel'])->whereUuid('uuid');
        Route::post('bus/trips/{uuid}/move', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'move'])->whereUuid('uuid');
        Route::post('bus/trips/{uuid}/board', [\Modules\Rides\Http\Controllers\Bus\DriverBusController::class, 'board'])->whereUuid('uuid');
        // O-26b: driving lessons — my packs, the lessons asked for, accept, done.
        Route::get('lessons/packs', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'packs']);
        Route::post('lessons/packs', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'store']);
        Route::post('lessons/packs/{uuid}/status', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'status'])->whereUuid('uuid');
        Route::get('lessons/sessions', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'sessions']);
        Route::post('lessons/sessions/{uuid}/answer', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'answer'])->whereUuid('uuid');
        Route::post('lessons/sessions/{uuid}/done', [\Modules\Rides\Http\Controllers\Lessons\DriverLessonController::class, 'done'])->whereUuid('uuid');
        // O-26c: find a driver — open requests near me, accept, my jobs, arrived → started → ended.
        Route::get('hire/open', [\Modules\Rides\Http\Controllers\Hire\DriverHireController::class, 'open']);
        Route::get('hire/mine', [\Modules\Rides\Http\Controllers\Hire\DriverHireController::class, 'mine']);
        Route::post('hire/{uuid}/accept', [\Modules\Rides\Http\Controllers\Hire\DriverHireController::class, 'accept'])->whereUuid('uuid');
        Route::post('hire/{uuid}/move', [\Modules\Rides\Http\Controllers\Hire\DriverHireController::class, 'move'])->whereUuid('uuid');
        Route::post('hire/{uuid}/release', [\Modules\Rides\Http\Controllers\Hire\DriverHireController::class, 'release'])->whereUuid('uuid');
        // O-25: the captain's boat — sea trips and yacht hires, bookings, boarding, more time.
        Route::get('marine/boat', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'boat']);
        Route::get('marine/listings', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'index']);
        Route::post('marine/listings', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'store']);
        Route::get('marine/listings/{uuid}', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'show'])->whereUuid('uuid');
        Route::post('marine/listings/{uuid}/move', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'move'])->whereUuid('uuid');
        Route::post('marine/board', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'board']);
        Route::post('marine/bookings/{uuid}/finish', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'finish'])->whereUuid('uuid');
        Route::post('marine/bookings/{uuid}/extension', [\Modules\Rides\Http\Controllers\Marine\DriverMarineController::class, 'extension'])->whereUuid('uuid');
        // O-03: which map to draw — Google or Mapbox (the admin's choice).
        Route::get('map-config', \Modules\Rides\Http\Controllers\MapConfigController::class);
        // O-47: «why don't requests reach me?» — every dispatch condition, passed or not.
        Route::get('dispatch-check', [\Modules\Rides\Http\Controllers\DispatchCheckController::class, 'driver']);
        // O-30: With Me — «I'm going there, take riders on my way».
        Route::get('pool', [\Modules\Rides\Http\Controllers\Driver\DriverPoolController::class, 'show']);
        Route::post('pool', [\Modules\Rides\Http\Controllers\Driver\DriverPoolController::class, 'open']);
        Route::delete('pool', [\Modules\Rides\Http\Controllers\Driver\DriverPoolController::class, 'close']);

        Route::get('offers', [DriverRideController::class, 'offers']);
        Route::post('offers/{uuid}/accept', [DriverRideController::class, 'accept'])->whereUuid('uuid');
        Route::post('offers/{uuid}/decline', [DriverRideController::class, 'decline'])->whereUuid('uuid');
        Route::post('offers/{uuid}/bid', [DriverRideController::class, 'bid'])->whereUuid('uuid');

        Route::get('earnings', [DriverEarningsController::class, 'summary']);
        Route::get('earnings/report', [DriverEarningsController::class, 'report']);

        // What the captain wants to be asked (D-32..D-37): categories, pickup range, Auto-Accept.
        Route::get('driving-hours', DriverDrivingHoursController::class);

        // Disputes and "the rider left without paying" reports, as cases of their own (D-229..D-238).
        Route::get('cases/options', [PartyCaseController::class, 'options']);
        Route::get('cases', [PartyCaseController::class, 'index']);
        Route::post('cases', [PartyCaseController::class, 'store']);
        Route::get('cases/{uuid}', [PartyCaseController::class, 'show'])->whereUuid('uuid');
        Route::post('cases/{uuid}/messages', [PartyCaseController::class, 'message'])->whereUuid('uuid');
        Route::post('cases/{uuid}/files', [PartyCaseController::class, 'file'])->whereUuid('uuid');
        Route::post('cases/{uuid}/appeal', [PartyCaseController::class, 'appeal'])->whereUuid('uuid');
        Route::get('preferences', [DriverPreferencesController::class, 'show']);
        Route::put('preferences', [DriverPreferencesController::class, 'update']);

        // The airport queue (D-276..D-280), its official points (D-281) and the restricted areas (D-282).
        Route::get('airport-queue', [DriverAirportController::class, 'show']);
        Route::post('airport-queue/join', [DriverAirportController::class, 'join']);
        Route::post('airport-queue/leave', [DriverAirportController::class, 'leave']);
        Route::get('restricted-zones', [DriverAirportController::class, 'restrictedZones']);

        // The captain's Safety Center (D-140, D-143): trusted contacts, preferences, blocked riders.
        Route::get('safety', [SafetyCenterController::class, 'show']);
        Route::post('safety/contacts', [SafetyCenterController::class, 'storeContact']);
        Route::put('safety/contacts/{id}', [SafetyCenterController::class, 'updateContact'])->whereNumber('id');
        Route::delete('safety/contacts/{id}', [SafetyCenterController::class, 'destroyContact'])->whereNumber('id');
        Route::put('safety/preferences', [SafetyCenterController::class, 'preferences']);
        Route::delete('safety/blocks/{id}', [SafetyCenterController::class, 'unblock'])->whereNumber('id');

        // Scheduled rides (D-260..269): upcoming bookings to reserve, my reservations, head out.
        Route::get('scheduled', [DriverScheduledController::class, 'available']);
        Route::get('scheduled/mine', [DriverScheduledController::class, 'mine']);
        Route::post('scheduled/{uuid}/reserve', [DriverScheduledController::class, 'reserve'])->whereUuid('uuid');
        Route::post('scheduled/{uuid}/release', [DriverScheduledController::class, 'release'])->whereUuid('uuid');
        Route::post('scheduled/{uuid}/start', [DriverScheduledController::class, 'start'])->whereUuid('uuid');

        // Wasl approval (docs/rides-plan.md §11.3): each call goes to ELM, so a tight limit.
        Route::get('regulator', [DriverRegulatorController::class, 'show']);
        Route::post('regulator/register', [DriverRegulatorController::class, 'register'])->middleware('throttle:5,60');
        Route::post('regulator/check', [DriverRegulatorController::class, 'check'])->middleware('throttle:5,60');

        Route::prefix('rides')->group(function () {
            Route::get('cancel-reasons', [DriverRideController::class, 'cancelReasons']);
            Route::get('rating-tags', [DriverRideController::class, 'ratingTags']);
            Route::get('current', [DriverRideController::class, 'current']);
            Route::get('/', [DriverRideController::class, 'index']);
            Route::get('{uuid}', [DriverRideController::class, 'show'])->whereUuid('uuid');
            Route::post('{uuid}/arrive', [DriverRideController::class, 'arrive'])->whereUuid('uuid');
            Route::post('{uuid}/start', [DriverRideController::class, 'start'])->whereUuid('uuid');
            Route::post('{uuid}/stops/{seq}', [DriverRideController::class, 'stop'])->whereUuid('uuid')->whereNumber('seq');
            Route::post('{uuid}/end', [DriverRideController::class, 'end'])->whereUuid('uuid');
            Route::post('{uuid}/collect-cash', [DriverRideController::class, 'collectCash'])->whereUuid('uuid');
            Route::post('{uuid}/cancel', [DriverRideController::class, 'cancel'])->whereUuid('uuid');
            Route::post('{uuid}/no-show', [DriverRideController::class, 'noShow'])->whereUuid('uuid');
            Route::post('{uuid}/rate', [DriverRideController::class, 'rate'])->whereUuid('uuid');
            Route::get('{uuid}/messages', [DriverRideController::class, 'messages'])->whereUuid('uuid');
            Route::post('{uuid}/messages', [DriverRideController::class, 'sendMessage'])->whereUuid('uuid');
            // O-09: in-app voice call with the rider (LiveKit) — start, answer, hang up.
            Route::post('{uuid}/calls', [\Modules\Rides\Http\Controllers\RideCallController::class, 'start'])->whereUuid('uuid');
            Route::post('{uuid}/calls/{call}/accept', [\Modules\Rides\Http\Controllers\RideCallController::class, 'accept'])->whereUuid('uuid')->whereUuid('call');
            Route::post('{uuid}/calls/{call}/end', [\Modules\Rides\Http\Controllers\RideCallController::class, 'end'])->whereUuid('uuid')->whereUuid('call');
            // Safety (D-141, D-142, D-156): share my trip, SOS.
            Route::get('{uuid}/share', [RideSafetyController::class, 'shareLink'])->whereUuid('uuid');
            Route::post('{uuid}/share', [RideSafetyController::class, 'share'])->whereUuid('uuid');
            Route::delete('{uuid}/share', [RideSafetyController::class, 'unshare'])->whereUuid('uuid');
            Route::post('{uuid}/sos', [RideSafetyController::class, 'sos'])->whereUuid('uuid');
            Route::post('{uuid}/contacts/share', [RideSafetyController::class, 'shareWithContacts'])->whereUuid('uuid');
            Route::post('{uuid}/block', [RideSafetyController::class, 'block'])->whereUuid('uuid');
            Route::post('{uuid}/report', [RideSafetyController::class, 'report'])->whereUuid('uuid');
            Route::post('{uuid}/safety-checks/{check}', [RideSafetyController::class, 'answerCheck'])->whereUuid(['uuid', 'check']);
            // The rider's new destination / stop: accept or decline (D-131..133).
            Route::post('{uuid}/changes/{change}/accept', [RideChangeController::class, 'accept'])->whereUuid(['uuid', 'change']);
            Route::post('{uuid}/changes/{change}/reject', [RideChangeController::class, 'reject'])->whereUuid(['uuid', 'change']);
        });
    });
