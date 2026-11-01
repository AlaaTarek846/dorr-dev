<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Consultations\ConsultationListings;
use Modules\Freelance\Services\Consultations\ConsultationOffers;
use Modules\Freelance\Services\Consultations\Consultations;

/** FL-L07, the freelancer's side: my consultation listings (each with its lengths, prices and weekly hours), the bookings, and answering or cancelling one. */
class FreelancerConsultationController extends Controller
{
    public function __construct(private readonly ConsultationListings $listings, private readonly Consultations $consultations) {}

    /** GET consultations/info — the lengths, limits and rules the form needs. */
    public function info(Request $request): JsonResponse
    {
        return ApiResponse::success($this->listings->info($this->me($request)), __('api.retrieved'));
    }

    /** GET consultations/offers — my listings, newest first. */
    public function offers(Request $request): JsonResponse
    {
        return ApiResponse::success($this->listings->mine($this->me($request))->map(fn ($o) => $this->listings->present($o, 'owner'))->values(), __('api.retrieved'));
    }

    /** GET consultations/offers/{uuid} */
    public function offer(Request $request, string $uuid): JsonResponse
    {
        return $this->listing($this->listings->find($this->me($request), $uuid));
    }

    /** POST consultations/offers — a new one, as a draft. */
    public function createOffer(Request $request): JsonResponse
    {
        return $this->listing($this->listings->create($this->me($request), $this->rules($request, true)), __('api.created'), 201);
    }

    /** PUT consultations/offers/{uuid} — only what is sent changes. */
    public function updateOffer(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return $this->listing($this->listings->update($this->listings->find($me, $uuid), $this->rules($request, false)), __('api.updated'));
    }

    /** POST consultations/offers/{uuid}/submit */
    public function submitOffer(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return $this->listing($this->listings->submit($this->listings->find($me, $uuid), $me), __('freelance.consultation_listing_submitted'));
    }

    public function pauseOffer(Request $request, string $uuid): JsonResponse
    {
        return $this->listing($this->listings->pause($this->listings->find($this->me($request), $uuid)), __('api.updated'));
    }

    public function resumeOffer(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return $this->listing($this->listings->resume($this->listings->find($me, $uuid), $me), __('api.updated'));
    }

    public function deleteOffer(Request $request, string $uuid): JsonResponse
    {
        $this->listings->delete($this->listings->find($this->me($request), $uuid));

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** GET consultations[?tab=upcoming|past|cancelled&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['tab' => ['nullable', Rule::in(['upcoming', 'past', 'cancelled'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $me = $this->me($request);
        $page = $this->consultations->page('freelancer', $me->id, $f['tab'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn ($c) => $this->consultations->present($c, 'freelancer'))->values(), __('api.retrieved'), 200,
            ApiPaginator::meta($page), ['counts' => $this->consultations->counts('freelancer', $me->id)],
        );
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->consultations->present($this->consultations->forFreelancer($this->me($request), $uuid), 'freelancer'), __('api.retrieved'));
    }

    /** POST consultations/{uuid}/cancel {reason?} — the client gets everything back. */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $me = $this->me($request);
        $c = $this->consultations->cancel($this->consultations->forFreelancer($me, $uuid), $me, $data['reason'] ?? null);

        return ApiResponse::success($this->consultations->present($c, 'freelancer'), __('api.updated'));
    }

    /** POST consultations/{uuid}/accept — the time is theirs; the client is told. */
    public function accept(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $c = $this->consultations->accept($this->consultations->forFreelancer($me, $uuid), $me);

        return ApiResponse::success($this->consultations->present($c, 'freelancer'), __('freelance.consultation_accepted'));
    }

    /** POST consultations/{uuid}/decline {reason?} — the client gets everything back. */
    public function decline(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $me = $this->me($request);
        $c = $this->consultations->decline($this->consultations->forFreelancer($me, $uuid), $me, $data['reason'] ?? null);

        return ApiResponse::success($this->consultations->present($c, 'freelancer'), __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function rules(Request $request, bool $new): array
    {
        $req = $new ? 'required' : 'sometimes';

        return $request->validate([
            'title' => [$req, 'string', 'min:3', 'max:120'],
            'about' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'options' => [$req, 'array', 'min:1', 'max:'.ConsultationOffers::MAX_OPTIONS],
            'options.*.minutes' => ['required', 'integer', Rule::in(ConsultationOffers::LENGTHS), 'distinct'],
            'options.*.price_minor' => ['required', 'integer', 'min:100'],
            'weekly' => [$req, 'array', 'min:1', 'max:21'],
            'weekly.*.day' => ['required', 'integer', 'between:0,6'],
            'weekly.*.from' => ['required', 'date_format:H:i'],
            'weekly.*.to' => ['required', 'date_format:H:i'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ]);
    }

    private function listing(\Modules\Freelance\Models\FreelanceConsultationOffer $o, ?string $message = null, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->listings->present($o->loadMissing('freelancer'), 'owner'), $message ?? __('api.retrieved'), $status);
    }

    private function me(Request $request): Freelancer
    {
        /** @var Freelancer */
        return $request->user('freelance_api');
    }
}
