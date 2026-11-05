<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceSpecialOffer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\SpecialOffers;
use Modules\User\Models\User;

/**
 * FL-L09, the client's side (a Dorr user): the special offers freelancers I worked with sent me, taking one (the work starts at once; a
 * consultation with the time I pick), saying no, and stopping a freelancer's special offers.
 */
class ClientSpecialOfferController extends Controller
{
    public function __construct(private readonly SpecialOffers $offers) {}

    /** GET special-offers[?tab=open|accepted|past&per_page=] — with the counts per tab. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', Rule::in(array_keys(FreelanceSpecialOffer::TABS))], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $me = $this->me($request);
        $page = $this->offers->page('client', $me->id, $f['tab'] ?? null, (int) ($f['per_page'] ?? 15));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($o) => $this->offers->present($o, 'client'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->offers->counts('client', $me->id)],
        );
    }

    /** GET special-offers/waiting — how many wait for me (the badge on the freelance home). */
    public function waiting(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => $this->offers->waiting($this->me($request))], __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->offers->present($this->offers->forClient($this->me($request), $uuid), 'client'), __('api.retrieved'));
    }

    /** POST special-offers/{uuid}/accept {request_id?, minutes?, starts_at?, topic?} — the last three for a consultation. */
    public function accept(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $offer = $this->offers->forClient($me, $uuid, false);
        $consultation = $offer->kind === FreelanceSpecialOffer::CONSULTATION;
        $data = $request->validate([
            'request_id' => ['nullable', 'string', 'max:64'],
            'minutes' => [$consultation ? 'required' : 'nullable', 'integer', 'min:1', 'max:600'],
            'starts_at' => [$consultation ? 'required' : 'nullable', 'date'],
            'topic' => [$consultation ? 'required' : 'nullable', 'string', 'min:10', 'max:1000'],
            // FL-L16: a consultation's session is recorded; booking it means agreeing.
            'recording_consent' => ['nullable', 'boolean'],
        ]);
        $this->offers->accept($offer, $me, $data);

        return ApiResponse::success($this->offers->present($this->offers->forClient($me, $uuid, false), 'client'), __('freelance.special_offer_accepted'));
    }

    /** POST special-offers/{uuid}/decline {reason?, mute?} */
    public function decline(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300'], 'mute' => ['nullable', 'boolean']]);
        $me = $this->me($request);
        $this->offers->decline($this->offers->forClient($me, $uuid, false), $me, $data['reason'] ?? null, (bool) ($data['mute'] ?? false));

        return ApiResponse::success($this->offers->present($this->offers->forClient($me, $uuid, false), 'client'), __('freelance.special_offer_declined'));
    }

    /** PUT special-offers/freelancers/{uuid}/mute {on} — stop (or allow again) this freelancer's special offers. */
    public function mute(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean']]);
        $me = $this->me($request);
        // Only a freelancer who sent me one: nobody else can be looked up this way.
        $freelancer = Freelancer::query()->where('uuid', $uuid)
            ->whereIn('id', FreelanceSpecialOffer::query()->where('client_id', $me->id)->select('freelancer_id'))->firstOrFail();
        $this->offers->mute($me, $freelancer, (bool) $data['on']);

        return ApiResponse::success(['muted' => (bool) $data['on']], __('api.updated'));
    }

    private function me(Request $request): User
    {
        /** @var User */
        return $request->user('user_api');
    }
}
