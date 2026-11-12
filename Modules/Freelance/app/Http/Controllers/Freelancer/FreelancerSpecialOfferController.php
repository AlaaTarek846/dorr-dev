<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceService;
use Modules\Freelance\Models\FreelanceSpecialOffer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\SpecialOffers;

/**
 * FL-L09, the freelancer's side: the clients I finished work with, what I can offer them, what a price leaves me, sending a special offer,
 * my offers with how they did, and taking one back before it is answered.
 */
class FreelancerSpecialOfferController extends Controller
{
    public function __construct(private readonly SpecialOffers $offers) {}

    /** GET special-offers[?tab=open|accepted|past&per_page=] — with the counts per tab and how my offers did. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', Rule::in(array_keys(FreelanceSpecialOffer::TABS))], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $me = $this->me($request);
        $page = $this->offers->page('freelancer', $me->id, $f['tab'] ?? null, (int) ($f['per_page'] ?? 15));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($o) => $this->offers->present($o, 'freelancer'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->offers->counts('freelancer', $me->id), 'stats' => $this->offers->stats($me)],
        );
    }

    /** GET special-offers/clients[?search=] — the clients I finished work with, each with whether an offer can go now. */
    public function clients(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return ApiResponse::success($this->offers->pastClients($this->me($request), $f['search'] ?? null), __('api.retrieved'));
    }

    /** GET special-offers/compose — my services and consultations that can be offered, my specialties, the rules and today's room. */
    public function compose(Request $request): JsonResponse
    {
        return ApiResponse::success($this->offers->compose($this->me($request)), __('api.retrieved'));
    }

    /** GET special-offers/quote?price_minor=&specialty_id= — what a price leaves me and what the client pays. */
    public function quote(Request $request): JsonResponse
    {
        $f = $request->validate(['price_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'specialty_id' => ['nullable', 'integer']]);

        return ApiResponse::success($this->offers->quote($this->me($request), (int) $f['price_minor'], isset($f['specialty_id']) ? (int) $f['specialty_id'] : null), __('api.retrieved'));
    }

    /** POST special-offers {client, kind, …, valid_days?, message?, request_id?} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'size:32'],
            'kind' => ['required', Rule::in(FreelanceSpecialOffer::KINDS)],
            'title' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'message' => ['nullable', 'string', 'max:1000'],
            'specialty_id' => ['nullable', 'integer'],
            'service' => ['nullable', 'uuid'],
            'level' => ['nullable', Rule::in(FreelanceService::LEVELS)],
            'consultation' => ['nullable', 'uuid'],
            'price_minor' => ['nullable', 'integer', 'min:1', 'max:1000000000000'],
            'discount_bp' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'delivery_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'revisions' => ['nullable', 'integer', 'min:0', 'max:20'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'request_id' => ['nullable', 'string', 'max:64'],
        ]);
        $offer = $this->offers->send($this->me($request), $data);

        return ApiResponse::success($this->offers->present($offer->load(['client.media', 'service', 'consultationOffer', 'specialty.translations']), 'freelancer'),
            __('freelance.special_offer_sent'), $offer->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->offers->present($this->offers->forFreelancer($this->me($request), $uuid), 'freelancer'), __('api.retrieved'));
    }

    public function withdraw(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->offers->withdraw($this->offers->forFreelancer($me, $uuid), $me);

        return ApiResponse::success($this->offers->present($this->offers->forFreelancer($me, $uuid), 'freelancer'), __('freelance.special_offer_withdrawn'));
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
