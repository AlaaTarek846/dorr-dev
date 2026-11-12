<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceOffer as Offer;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Offers\FreelancerOffers;
use Modules\Freelance\Services\Offers\Loyalty;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The freelancer's offers (FL-301): whether they may offer on a project and what an offer leaves them, sending it with its files,
 * their offer on a project, all their offers, and their own offer's files. Only projects they can see (FL-206) can be offered on.
 */
class FreelancerOfferController extends Controller
{
    public function __construct(private readonly FreelancerOffers $offers, private readonly FreelancerProjects $projects) {}

    /**
     * GET projects/{uuid}/offer-preview[?price_minor=][&team=] — can I offer (and if not, why), my quota, and the net at a price.
     * FS-205: with `team` (a team I own) it is the team's quota that decides (`team_quota`), not mine.
     */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['price_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000'], 'team' => ['nullable', 'uuid'], 'signer' => ['nullable', 'uuid']]);
        $me = $this->me($request);
        $project = $this->projects->find($me, $uuid);
        // FS-206c: a PM names the Owner who signs; the checks and the net are the signer's.
        [$team, , $signer] = empty($data['team']) ? [null, [], null] : app(\Modules\Freelance\Services\FreelancerTeam\TeamOffers::class)->prepare($me, $data['team'], [], $data['signer'] ?? null);
        $me = $signer ?? $me;
        // FL-L09: a client back for more gets the loyalty step they earned off the price; the net is worked out on what they pay.
        $loyalty = app(Loyalty::class);
        $deal = isset($data['price_minor']) ? $loyalty->apply($me, (int) $project->client_id, (int) $data['price_minor']) : null;

        return ApiResponse::success([
            'blocked' => $this->offers->blocked($me, $project, $team),
            'quota' => $this->offers->quota($me),
            'team_quota' => $team === null ? null : app(\Modules\Freelance\Services\FreelancerTeam\TeamOffers::class)->quota($team),
            'min_price_minor' => $this->minPrice($project),
            'breakdown' => $deal === null ? null : $this->offers->preview($project, $deal['price_minor'], $me, $team)
                + ['list_price_minor' => $deal['list_price_minor'], 'loyalty_bp' => $deal['loyalty_bp'], 'saving_minor' => $deal['saving_minor']],
            'loyalty' => $loyalty->discountFor($me, (int) $project->client_id),
        ], __('api.retrieved'));
    }

    /** POST projects/{uuid}/offers (multipart: price_minor, duration_days, details, files[]) */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:'.(int) config('freelance.projects.max_duration_days', 365)],
            'details' => ['required', 'string', 'min:'.(int) config('freelance.offers.details_min', 30), 'max:'.(int) config('freelance.offers.details_max', 3000)],
            'files' => ['nullable', 'array', 'max:'.(int) config('freelance.offers.max_attachments', 5)],
            'files.*' => ['file', 'max:'.(int) config('freelance.upload_max_kb', 20480), 'mimes:'.implode(',', (array) config('freelance.projects.attachment_types')), new \Modules\Freelance\Rules\CleanUpload],
            'answers' => ['nullable', 'array', 'max:10'],
            'answers.*' => ['nullable', 'string', 'max:1000'],
        ] + self::rightsRules() + self::teamRules(true));
        $me = $this->me($request);
        $offer = $this->offers->send($me, $this->projects->find($me, $uuid), $data, $request->file('files', []));

        return ApiResponse::created($this->present($offer->load('project')), __('freelance.offer_sent'));
    }

    /** GET projects/{uuid}/my-offer — `{offer: null}` when I haven't offered there (the envelope would turn a bare null into []). */
    public function mine(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $offer = $this->offers->mine($me, $this->projects->find($me, $uuid));

        return ApiResponse::success(['offer' => $offer === null ? null : $this->present($offer->load('project'))], __('api.retrieved'));
    }

    /** GET offers — all my offers, newest first. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->offers->myOffers($this->me($request), (int) ($data['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (Offer $o) => $this->present($o))->values(), __('api.retrieved'));
    }

    /** PUT offers/{offer} {price_minor?, duration_days?, details?} — change an offer that is still open. */
    public function update(Request $request, string $offer): JsonResponse
    {
        $data = $request->validate([
            'price_minor' => ['sometimes', 'integer', 'min:1', 'max:100000000000'],
            'duration_days' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('freelance.projects.max_duration_days', 365)],
            'details' => ['sometimes', 'string', 'min:'.(int) config('freelance.offers.details_min', 30), 'max:'.(int) config('freelance.offers.details_max', 3000)],
            'answers' => ['sometimes', 'array', 'max:10'],
            'answers.*' => ['nullable', 'string', 'max:1000'],
        ] + self::rightsRules() + self::teamRules(false));
        $me = $this->me($request);

        return ApiResponse::success($this->present($this->offers->edit($me, $this->offers->find($me, $offer), $data)), __('api.updated'));
    }

    /** POST offers/{offer}/withdraw */
    public function withdraw(Request $request, string $offer): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->present($this->offers->withdraw($me, $this->offers->find($me, $offer))), __('api.updated'));
    }

    /** GET offers/{offer}/revisions — what the offer said before each change. */
    public function revisions(Request $request, string $offer): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->offers->history($this->offers->find($me, $offer)), __('api.retrieved'));
    }

    /** GET offers/{offer}/attachments/{media} — a file of my own offer. */
    public function attachment(Request $request, string $offer, int $media): BinaryFileResponse
    {
        $file = Offer::query()->where('uuid', $offer)->where('freelancer_id', $this->me($request)->id)->firstOrFail()->getMedia('attachments')->firstWhere('id', $media) ?? abort(404);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /**
     * FL-L13: the terms of an offer or a service — also arrives as form fields (`rights[model]=license`) with the files.
     *
     * @return array<string, list<mixed>>
     */
    public static function rightsRules(): array
    {
        return [
            'rights' => ['sometimes', 'nullable', 'array'],
            'rights.model' => ['sometimes', 'string', 'max:16'],
            'rights.commercial' => ['sometimes', 'nullable'],
            'rights.exclusive' => ['sometimes', 'nullable'],
            'rights.term_months' => ['sometimes', 'nullable'],
            'rights.territory' => ['sometimes', 'nullable', 'string', 'max:16'],
            'rights.portfolio' => ['sometimes', 'nullable'],
        ];
    }

    /**
     * FS-205: an offer in a team's name - `team` (the team's uuid, sent only with a new offer). FS-213 (owner 2026-11-08): the offer no longer
     * names who works on it - `staff` is not read any more (an old app that still sends it is not refused); the team's people are put on the
     * parts and tasks once the project starts, and the client reads them on the team's profile.
     *
     * @return array<string, list<mixed>>
     */
    public static function teamRules(bool $sending): array
    {
        return [
            'team' => $sending ? ['nullable', 'uuid'] : ['prohibited'],
            'signer' => $sending ? ['nullable', 'uuid'] : ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Offer $offer): array
    {
        return $this->offers->present($offer, 'freelancer', "offers/{$offer->uuid}/attachments");
    }

    private function minPrice($project): ?int
    {
        $min = app(\Modules\Freelance\Services\Policy\FreelancePolicies::class)->for($project->country_id, $project->freelance_specialty_id)->get('project.min_budget_minor');

        return $min === null ? null : (int) $min;
    }

    private function me(Request $request): Freelancer
    {
        return $request->user('freelance_api');
    }
}
