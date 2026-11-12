<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Services\Consultations\ConsultationOffers;
use Modules\Freelance\Services\Consultations\ConsultationReviews;
use Modules\Freelance\Services\Consultations\Consultations;
use Modules\User\Models\User;

/**
 * FL-L07, the client's side: the consultations on offer (a list of their own, next to the ready-made services), one with its free times,
 * booking one (paid from the wallet at once), my consultations, and cancelling, confirming or complaining about one.
 */
class ClientConsultationController extends Controller
{
    public function __construct(
        private readonly ConsultationOffers $offers,
        private readonly Consultations $consultations,
        private readonly ConsultationReviews $reviews,
    ) {}

    /** GET consultation-offers[?search=&per_page=] — what clients can book now. */
    public function market(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->offers->market($this->me($request), $f['search'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn ($o) => $this->offers->card($o))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET consultation-offers/{uuid} — one listing: what is sold and the rules. */
    public function offer(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->offers->presentForClient($this->offers->bookable($me, $uuid), $me), __('api.retrieved'));
    }

    /** GET consultation-offers/{uuid}/slots?minutes= — the free start times (UTC), soonest first. */
    public function slots(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate(['minutes' => ['required', 'integer', Rule::in(ConsultationOffers::LENGTHS)]]);
        $offer = $this->offers->bookable($this->me($request), $uuid);

        return ApiResponse::success([
            'minutes' => (int) $f['minutes'],
            'slots' => array_map(fn ($s) => $s->toIso8601String(), $this->offers->slots($offer, (int) $f['minutes'])),
        ], __('api.retrieved'));
    }

    /** GET consultations[?tab=upcoming|past|cancelled&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', Rule::in(['upcoming', 'past', 'cancelled'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $me = $this->me($request);
        $page = $this->consultations->page('client', $me->id, $f['tab'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($c) => $this->consultations->present($c, 'client'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->consultations->counts('client', $me->id)],
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->answer($this->consultations->forClient($this->me($request), $uuid));
    }

    /** POST consultations — books a free time and holds the money. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'offer' => ['required', 'uuid'],
            'minutes' => ['required', 'integer', Rule::in(ConsultationOffers::LENGTHS)],
            'starts_at' => ['required', 'date'],
            'topic' => ['required', 'string', 'min:10', 'max:1000'],
            'request_id' => ['nullable', 'string', 'max:64'],
            // FL-L16: the session is recorded; booking needs the yes (`freelance_consultation_recording_consent_required`).
            'recording_consent' => ['nullable', 'boolean'],
        ]);

        return $this->answer($this->consultations->book($this->me($request), $data), __('freelance.consultation_booked'), 201);
    }

    /** POST consultations/{uuid}/cancel {reason?} */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $me = $this->me($request);

        return $this->answer($this->consultations->cancel($this->consultations->forClient($me, $uuid), $me, $data['reason'] ?? null), __('api.updated'));
    }

    /** POST consultations/{uuid}/confirm — it went well: the freelancer is paid now. */
    public function confirm(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return $this->answer($this->consultations->confirm($this->consultations->forClient($me, $uuid), $me), __('api.updated'));
    }

    /** POST consultations/{uuid}/review {stars 1..5, comment?} — once it is over, within the rating days. */
    public function review(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']]);
        $me = $this->me($request);
        $c = $this->consultations->forClient($me, $uuid);
        $this->reviews->rate($c, $me, (int) $data['stars'], $data['comment'] ?? null);

        return $this->answer($c->refresh(), __('freelance.consultation_rated'));
    }

    /** POST consultations/{uuid}/complain {text} */
    public function complain(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'min:10', 'max:2000']]);
        $me = $this->me($request);

        return $this->answer($this->consultations->complain($this->consultations->forClient($me, $uuid), $me, $data['text']), __('api.updated'));
    }

    private function answer(Consultation $c, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->consultations->present($c, 'client'), $message ?? __('api.retrieved'), $status);
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
