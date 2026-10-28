<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Models\FreelanceProject as Project;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Projects\ApprovalTrail;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\ProjectOffersWindow;
use Modules\Freelance\Services\Projects\ProjectReviews;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Freelance → Projects review (FL-203): the queue (the oldest waiting first), one project with its files (opened through
 * this signed-in endpoint, never a public link), a fix before the decision, and the decision — publish, ask for changes, reject.
 */
class FreelanceProjectReviewController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ClientProjects $projects, private readonly ProjectReviews $reviews, private readonly ProjectOffersWindow $window) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-projects', [
            ['view', ['index', 'show', 'attachment', 'options', 'trail']],
            ['update', ['update', 'publish', 'requestChanges', 'reject', 'reopenOffers', 'closeOffers']],
        ]);
    }

    /** GET freelance-projects[?status=&country_id=&specialty_id=&search=&per_page=] — `pagination.counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::enum(Status::class)],
            'country_id' => ['nullable', 'integer'],
            'specialty_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Project::query()->with(['client', 'specialty.translations'])
            ->where('status', '!=', Status::Draft->value)
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['country_id']), fn ($q) => $q->where('country_id', $f['country_id']))
            ->when(isset($f['specialty_id']), fn ($q) => $q->where('freelance_specialty_id', $f['specialty_id']))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhereHas('client', fn ($c) => $c->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->orderByRaw("status = 'pending_review' desc")->orderBy('submitted_at')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $counts = Project::query()->where('status', '!=', Status::Draft->value)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();
        $zero = array_fill_keys(array_map(fn (Status $s) => $s->value, array_filter(Status::cases(), fn (Status $s) => $s !== Status::Draft)), 0);

        return ApiResponse::success(
            $page->getCollection()->map(fn (Project $p) => $this->row($p))->values(),
            __('api.retrieved'), 200, ApiPaginator::meta($page) + ['counts' => array_merge($zero, $counts)],
        );
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'specialties' => FreelanceSpecialty::query()->with('translations')->where('status', true)->get()->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values(),
        ], __('api.retrieved'));
    }

    public function show(string $uuid, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->detail($this->find($uuid)), $message ?? __('api.retrieved'));
    }

    /** PUT freelance-projects/{uuid} — fix a project that is waiting in the queue (same fields and checks as the client's form). */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('freelance.projects.description_max', 5000)],
            'specialty_id' => ['sometimes', 'nullable', 'integer'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['integer', 'distinct'],
            'budget_type' => ['sometimes', Rule::in(Project::BUDGET_TYPES)],
            'budget_min_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'budget_max_minor' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000000000'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('freelance.projects.max_duration_days', 365)],
            'visibility' => ['sometimes', Rule::in(Project::VISIBILITIES)],
        ]);
        $this->reviews->edit($this->find($uuid), $data, $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    public function publish(Request $request, string $uuid): JsonResponse
    {
        $this->reviews->publish($this->find($uuid), $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    /** POST freelance-projects/{uuid}/request-changes {reason} */
    public function requestChanges(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->reviews->requestChanges($this->find($uuid), $data['reason'], $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    /** POST freelance-projects/{uuid}/reject {reason} — the client can still read it, edit the project and send it again. */
    public function reject(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $this->reviews->reject($this->find($uuid), $data['reason'], $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    /** POST freelance-projects/{uuid}/offers/reopen — a new window, with no limit for an admin. */
    public function reopenOffers(Request $request, string $uuid): JsonResponse
    {
        $this->window->reopen($this->find($uuid), 'admin', $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    public function closeOffers(Request $request, string $uuid): JsonResponse
    {
        $this->window->close($this->find($uuid), 'admin', $this->adminId($request));

        return $this->show($uuid, __('api.updated'));
    }

    /** GET freelance-projects/{uuid}/attachments/{media} — opens a client's file for the reviewer. */
    public function attachment(string $uuid, int $media): BinaryFileResponse
    {
        $file = $this->find($uuid)->getMedia('attachments')->firstWhere('id', $media) ?? abort(404);

        return tap(response()->file($file->getPath(), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** GET freelance-projects/{uuid}/trail — the project's record of approvals (FL-406), with who did each step. */
    public function trail(string $uuid): JsonResponse
    {
        return ApiResponse::success(app(ApprovalTrail::class)->list((int) $this->find($uuid)->id, null, 'admin'), __('api.retrieved'));
    }

    private function find(string $uuid): Project
    {
        return Project::query()->where('uuid', $uuid)->where('status', '!=', Status::Draft->value)
            ->with(['client', 'specialty.translations', 'skills.translations', 'media', 'events'])->firstOrFail();
    }

    private function adminId(Request $request): int
    {
        return (int) $request->user('admin_api')->getKey();
    }

    /** @return array<string, mixed> */
    private function row(Project $p): array
    {
        return [
            'uuid' => $p->uuid,
            'title' => $p->title,
            'status' => $p->status->value,
            'country_id' => $p->country_id,
            'specialty' => $p->specialty?->translatedName(),
            'budget' => ['type' => $p->budget_type, 'min_minor' => $p->budget_min_minor, 'max_minor' => $p->budget_type === 'range' ? $p->budget_max_minor : null, 'currency' => $p->currency_code],
            'client_name' => $p->client?->name,
            'client_phone' => $p->client?->phone,
            'submitted_at' => $p->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Project $p): array
    {
        $project = $this->projects->present($p);
        // The reviewer's page is about files and what the client wrote: the attachments open through the admin endpoint.
        $project['attachments'] = array_map(fn ($a) => ['path' => "freelance-projects/{$p->uuid}/attachments/{$a['id']}"] + $a, $project['attachments']);

        return $this->row($p) + [
            'description' => $project['description'],
            'specialty_id' => $p->freelance_specialty_id,
            'skills' => $project['skills'],
            'duration_days' => $project['duration_days'],
            'visibility' => $project['visibility'],
            'attachments' => $project['attachments'],
            'status_reason' => $project['status_reason'],
            'missing' => $this->projects->missing($p),
            'client' => $p->client === null ? null : ['id' => $p->client->id, 'name' => $p->client->name, 'phone' => $p->client->phone, 'country_id' => $p->client->country_id],
            'can_decide' => $p->status === Status::PendingReview,
            'offers' => $this->window->present($p, false) + ['reopen_count' => $p->reopen_count, 'count' => \Modules\Freelance\Models\FreelanceOffer::query()->where('project_id', $p->id)->where('status', '!=', 'withdrawn')->count()],
            'timeline' => $p->events->sortBy('id')->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'actor' => $e->actor_type, 'reason' => $e->payload['reason'] ?? null, 'at' => $e->created_at?->toIso8601String()])->values()->all(),
        ];
    }
}
