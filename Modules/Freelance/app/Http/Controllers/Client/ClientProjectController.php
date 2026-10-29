<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Offers\ClientOffers;
use Modules\Freelance\Services\Offers\ClientOfferViews;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The client's projects on the Dorr user app (FL-201 / FL-202): a draft saved as it is typed, its files, sending it for review,
 * taking it back, cancelling it. Everything is scoped to the signed-in client; a project is addressed by its uuid.
 */
class ClientProjectController extends Controller
{
    public function __construct(private readonly ClientProjects $projects, private readonly ProjectOffersWindow $window) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(FreelanceProjectStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $page = $this->projects->mine($this->me($request), $data['status'] ?? null, (int) ($data['per_page'] ?? 15));

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (FreelanceProject $p) => $this->projects->present($p))->values(), __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->projects->present($this->projects->find($this->me($request), $uuid)), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $project = $this->projects->create($this->me($request), $request->validate($this->rules()));

        return ApiResponse::created($this->projects->present($project), __('api.created'));
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);

        return ApiResponse::success($this->projects->present($this->projects->update($project, $request->validate($this->rules()))), __('api.updated'));
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $this->projects->delete($this->projects->find($this->me($request), $uuid));

        return ApiResponse::success(null, __('api.deleted'));
    }

    public function submit(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->projects->present($this->projects->submit($this->projects->find($me, $uuid), $me)), __('freelance.project_submitted'));
    }

    /** POST projects/{uuid}/duplicate — asks for the project again: a new draft with the same details, ready to change (FL-209). */
    public function duplicate(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::created($this->projects->present($this->projects->duplicate($this->projects->find($me, $uuid), $me)), __('freelance.project_duplicated'));
    }

    /** GET projects/{uuid}/preview — the last look before sending: the project as read, with what is missing or doesn't add up (FL-210). */
    public function preview(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->projects->preview($this->projects->find($this->me($request), $uuid)), __('api.retrieved'));
    }

    /** PUT projects/{uuid}/cost-cap {max_cost_minor} — the most the client will pay in all, fee included (FL-210). */
    public function costCap(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['max_cost_minor' => ['required', 'integer', 'min:1', 'max:100000000000']]);

        return ApiResponse::success($this->projects->present($this->projects->setCostCap($this->projects->find($this->me($request), $uuid), (int) $data['max_cost_minor'])), __('api.updated'));
    }

    /** DELETE projects/{uuid}/cost-cap — no limit any more. */
    public function removeCostCap(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success($this->projects->present($this->projects->setCostCap($this->projects->find($this->me($request), $uuid), null)), __('api.updated'));
    }

    public function withdraw(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);

        return ApiResponse::success($this->projects->present($this->projects->withdraw($this->projects->find($me, $uuid), $me)), __('api.updated'));
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'reason_code' => ['nullable', Rule::enum(\Modules\Freelance\Enums\FreelanceCancelReason::class)]]);
        $me = $this->me($request);

        return ApiResponse::success($this->projects->present($this->projects->cancel($this->projects->find($me, $uuid), $me, $data['reason'] ?? null, $data['reason_code'] ?? null)), __('api.updated'));
    }

    /**
     * GET projects/{uuid}/offers?sort=newest|cheapest|fastest|completeness&status=all|shortlisted|…&page=&per_page=(15) — the offers on my project, with
     * what helps to compare the freelancers who sent them (FL-305).
     */
    public function offers(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'sort' => ['nullable', Rule::in(ClientOfferViews::SORTS)],
            'status' => ['nullable', Rule::in(['all', 'submitted', 'viewed', 'shortlisted', 'rejected', 'accepted'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $me = $this->me($request);
        $project = $this->projects->find($me, $uuid);
        // Opening the list is looking at the offers: the new ones become «seen» (once), and their freelancers are told.
        if ((int) ($data['page'] ?? 1) === 1) {
            app(ClientOffers::class)->markSeen($project, $me);
        }
        $page = app(ClientOfferViews::class)->page($project, $data['status'] ?? null, $data['sort'] ?? 'newest');

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    /** GET projects/{uuid}/offers/compare?ids=a,b,c — two or three offers side by side, with what stands out in each. */
    public function compareOffers(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'string', 'max:200']]);
        $project = $this->projects->find($this->me($request), $uuid);
        $ids = array_values(array_filter(array_map('trim', explode(',', $data['ids']))));

        return ApiResponse::success(app(ClientOfferViews::class)->compare($project, $ids), __('api.retrieved'));
    }

    /** GET projects/{uuid}/offers/{offer} — one offer, as the compare screen opens it. */
    public function showOffer(Request $request, string $uuid, string $offer): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);
        $found = app(ClientOffers::class)->find($project, $offer);
        abort_if($found->status->value === 'withdrawn', 404);

        return ApiResponse::success(app(ClientOfferViews::class)->present($project, $found), __('api.retrieved'));
    }

    /** GET projects/{uuid}/offers/{offer}/freelancer — the profile of the freelancer who sent it (no phone, no email). */
    public function offerFreelancer(Request $request, string $uuid, string $offer): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);
        $found = app(ClientOffers::class)->find($project, $offer);
        abort_if($found->status->value === 'withdrawn', 404);

        return ApiResponse::success(app(ClientOfferViews::class)->profile($project, $found), __('api.retrieved'));
    }

    /** POST projects/{uuid}/offers/{offer}/shortlist | unshortlist | reject {reason?} | choose — what the client decides about one offer. */
    public function decideOffer(Request $request, string $uuid, string $offer, string $action): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $me = $this->me($request);
        $service = app(ClientOffers::class);
        $found = $service->find($this->projects->find($me, $uuid), $offer);
        $done = match ($action) {
            'shortlist' => $service->shortlist($found, $me),
            'unshortlist' => $service->unshortlist($found, $me),
            'reject' => $service->reject($found, $me, $data['reason'] ?? null),
            default => $service->choose($found, $me),
        };

        $project = $this->projects->find($me, $uuid);

        return ApiResponse::success(app(ClientOfferViews::class)->present($project, $done->load('freelancer', 'media')), $action === 'choose' ? __('freelance.offer_chosen') : __('api.updated'));
    }

    /** POST projects/{uuid}/offers/{offer}/chat - opens (or finds) the room to talk about this offer. */
    public function openChat(Request $request, string $uuid, string $offer): JsonResponse
    {
        $me = $this->me($request);
        $found = app(ClientOffers::class)->find($this->projects->find($me, $uuid), $offer);
        abort_if($found->status->value === 'withdrawn', 404);
        $room = app(\Modules\Freelance\Services\Chat\OfferChat::class)->open($found, $me);

        return ApiResponse::success(app(\Modules\Freelance\Services\Chat\FreelanceChatService::class)->presentConversation($room->load(['client', 'freelancer']), $me), __('api.retrieved'));
    }

    /** GET projects/{uuid}/offers/{offer}/attachments/{media} — a file of an offer on my project. */
    public function offerAttachment(Request $request, string $uuid, string $offer, int $media): BinaryFileResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);
        $file = \Modules\Freelance\Models\FreelanceOffer::query()->where('project_id', $project->id)->where('uuid', $offer)->firstOrFail()->getMedia('attachments')->firstWhere('id', $media) ?? abort(404);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** POST projects/{uuid}/offers/close — stop taking offers before the time is up. */
    public function closeOffers(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->window->close($this->projects->find($me, $uuid), 'client', $me->id);

        return $this->show($request, $uuid);
    }

    /** POST projects/{uuid}/offers/reopen — a new window (a few times). */
    public function reopenOffers(Request $request, string $uuid): JsonResponse
    {
        $me = $this->me($request);
        $this->window->reopen($this->projects->find($me, $uuid), 'client', $me->id);

        return $this->show($request, $uuid);
    }

    /** POST projects/{uuid}/attachments (multipart: file) */
    public function addAttachment(Request $request, string $uuid): JsonResponse
    {
        $request->validate(['file' => [
            'required', 'file', 'max:'.(int) config('freelance.upload_max_kb', 20480),
            'mimes:'.implode(',', (array) config('freelance.projects.attachment_types')), new \Modules\Freelance\Rules\CleanUpload,
        ]]);
        $project = $this->projects->find($this->me($request), $uuid);
        $this->projects->addAttachment($project, $request->file('file'));

        return ApiResponse::created($this->projects->present($project->refresh()->load(['specialty.translations', 'skills.translations', 'media', 'events'])), __('api.created'));
    }

    public function removeAttachment(Request $request, string $uuid, int $media): JsonResponse
    {
        $project = $this->projects->find($this->me($request), $uuid);
        $this->projects->removeAttachment($project, $media);

        return ApiResponse::success($this->projects->present($project->refresh()->load(['specialty.translations', 'skills.translations', 'media', 'events'])), __('api.deleted'));
    }

    /** GET projects/{uuid}/attachments/{media} — the owner's own file. */
    public function attachment(Request $request, string $uuid, int $media): BinaryFileResponse
    {
        $file = $this->projects->find($this->me($request), $uuid)->getMedia('attachments')->firstWhere('id', $media) ?? abort(404);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** GET projects/budget-rules?specialty_id= — the smallest and biggest budget allowed for the form. */
    public function budgetRules(Request $request): JsonResponse
    {
        $data = $request->validate(['specialty_id' => ['nullable', 'integer']]);
        $countryId = FreelanceCountry::of($this->me($request)) ?? abort(403);

        return ApiResponse::success($this->projects->budgetRules($countryId, isset($data['specialty_id']) ? (int) $data['specialty_id'] : null), __('api.retrieved'));
    }

    /** @return array<string, mixed> a draft may be saved half done: only the shape is checked here, completeness on submit */
    private function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('freelance.projects.description_max', 5000)],
            'specialty_id' => ['sometimes', 'nullable', 'integer'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['integer', 'distinct'],
            'budget_type' => ['sometimes', Rule::in(FreelanceProject::BUDGET_TYPES)],
            'budget_min_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'budget_max_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'max_cost_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('freelance.projects.max_duration_days', 365)],
            'visibility' => ['sometimes', Rule::in(FreelanceProject::VISIBILITIES)],
            'questions' => ['sometimes', 'nullable', 'array', 'max:'.(int) config('freelance.projects.max_questions', 5)],
            'questions.*' => ['nullable', 'string', 'max:300'],
        ];
    }

    private function me(Request $request): User
    {
        return $request->user('user_api');
    }
}
