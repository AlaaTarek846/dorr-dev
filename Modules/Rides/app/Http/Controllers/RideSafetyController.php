<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Enums\RideStatus;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideShare;
use Modules\Rides\Services\Lifecycle\RideCancellationService;
use Modules\Rides\Services\Safety\RideShareService;
use Modules\Rides\Services\Safety\SafetyCenterService;
use Modules\Rides\Services\Safety\SafetyCheckService;
use Modules\Rides\Services\Safety\SafetyContacts;
use Modules\Rides\Services\Safety\SafetyIncidentService;

/**
 * Safety for both apps — the rider (`mobile/v1/rides/{uuid}/…`) and the captain
 * (`driver/v1/rides/{uuid}/…`): the share link to send family, and SOS.
 */
class RideSafetyController extends Controller
{
    public function shareLink(Request $request, string $uuid, RideShareService $shares): JsonResponse
    {
        return ApiResponse::success($this->present($shares->active($this->ride($request, $uuid), $request->user())), __('api.retrieved'));
    }

    public function share(Request $request, string $uuid, RideShareService $shares): JsonResponse
    {
        return ApiResponse::success($this->present($shares->share($this->ride($request, $uuid), $request->user())), __('rides.messages.shared'), 201);
    }

    public function unshare(Request $request, string $uuid, RideShareService $shares): JsonResponse
    {
        $shares->revoke($this->ride($request, $uuid), $request->user());

        return ApiResponse::success(null, __('rides.messages.unshared'));
    }

    public function sos(Request $request, string $uuid, SafetyIncidentService $safety): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:65000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $result = $safety->sos(
            $this->ride($request, $uuid), $request->user(),
            isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null,
            isset($data['accuracy_m']) ? (int) $data['accuracy_m'] : null, $data['note'] ?? null,
        );

        return ApiResponse::success([
            'incident_uuid' => $result['incident']->uuid,
            'emergency_number' => $result['emergency_number'],
        ], __('rides.messages.sos_sent'), 201);
    }

    /** "Send to my trusted contacts" — all of them, or the ones picked (R-111, R-220). */
    public function shareWithContacts(Request $request, string $uuid, SafetyContacts $contacts): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:10'], 'ids.*' => ['integer']]);
        $sent = $contacts->shareNow($this->ride($request, $uuid), $request->user(), array_map('intval', $data['ids'] ?? []));

        return ApiResponse::success(['sent' => $sent], __('rides.messages.contacts_shared', ['count' => $sent]));
    }

    /** Never be matched with the other person of this ride again (R-225, D-151, D-187, D-410). */
    public function block(Request $request, string $uuid, SafetyCenterService $center): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $center->block($request->user(), $this->ride($request, $uuid), $data['reason'] ?? null);

        return ApiResponse::success(null, __('rides.messages.blocked'));
    }

    /** The rider keeps this ride's captain as a favourite (R-226). */
    public function favorite(Request $request, string $uuid, SafetyCenterService $center): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $center->favorite($request->user(), $this->ride($request, $uuid), (bool) $data['on']);

        return ApiResponse::success(['favorite' => (bool) $data['on']], __('api.updated'));
    }

    /** A report (R-117, D-124, D-130, D-152..154); an accident comes back with what to do now (D-155). */
    public function report(Request $request, string $uuid, SafetyIncidentService $safety): JsonResponse
    {
        $who = $request->user() instanceof Driver ? 'driver' : 'user';
        $data = $request->validate([
            'type' => ['required', Rule::in(SafetyIncidentService::REPORTS[$who])],
            'note' => ['nullable', 'string', 'max:500'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $ride = $this->ride($request, $uuid);
        $incident = $safety->report($ride, $request->user(), $data['type'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null, null, $data['note'] ?? null);

        return ApiResponse::success([
            'incident_uuid' => $incident->uuid,
            'emergency_number' => SafetyIncidentService::emergencyNumber($ride),
            'guidance' => $data['type'] === 'accident' ? __('rides.accident_guidance') : null,
        ], __('rides.messages.reported'), 201);
    }

    /** "This isn't the car" (R-224): the ride is cancelled free and the safety team reviews it. */
    public function vehicleMismatch(Request $request, string $uuid, SafetyIncidentService $safety, RideCancellationService $cancel): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $ride = $this->ride($request, $uuid);
        if (! in_array($ride->status, [RideStatus::Accepted, RideStatus::Arrived], true)) {
            throw new RidesException('cannot_cancel', 409, ['status' => $ride->status->value], ['status' => $ride->status->value]);
        }
        $safety->report($ride, $request->user(), 'vehicle_mismatch', null, null, null, $data['note'] ?? null);
        $ride = $cancel->byRider($request->user(), $ride, null, $data['note'] ?? __('rides.safety_types.vehicle_mismatch'), $request->header('Idempotency-Key'), true);

        return ApiResponse::success(['status' => $ride->status->value], __('rides.messages.mismatch_reported'));
    }

    /** "I'm OK" / "I need help" to an "Are you OK?" (R-222, R-223, D-144). */
    public function answerCheck(Request $request, string $uuid, string $check, SafetyCheckService $checks): JsonResponse
    {
        $data = $request->validate([
            'answer' => ['required', Rule::in(['ok', 'help'])],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $result = $checks->answer($this->ride($request, $uuid), $request->user(), $check, $data['answer'], isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null);

        return ApiResponse::success(['answer' => $data['answer'], 'emergency_number' => $result['emergency_number']], __('api.updated'));
    }

    /** @return array{url: string, created_at: ?string, views: int}|null */
    private function present(?RideShare $share): ?array
    {
        return $share === null ? null : ['url' => $share->url(), 'created_at' => $share->created_at?->toIso8601String(), 'views' => $share->views];
    }

    /** The ride, only if this person is on it (the rider who booked it, or its captain). */
    private function ride(Request $request, string $uuid): Ride
    {
        $user = $request->user();

        return Ride::query()->where('uuid', $uuid)
            ->where($user instanceof Driver ? 'driver_id' : 'user_id', $user->getKey())
            ->first() ?? throw new RidesException('ride_not_found', 404);
    }
}
