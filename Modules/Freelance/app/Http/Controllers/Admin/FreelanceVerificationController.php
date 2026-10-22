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
use Modules\Freelance\Models\FreelanceVerificationDocument;
use Modules\Freelance\Models\FreelanceVerificationRequest as VRequest;
use Modules\Freelance\Services\Verification\FreelanceDocumentRequirements;
use Modules\Freelance\Services\Verification\FreelancerVerification;
use Modules\Freelance\Services\Verification\VerificationGuides;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Freelance → Verification requests (FL-107 / FL-108): the queue, one request with the freelancer's documents
 * (opened through this signed-in endpoint, never a public link) and the decision — approve, reject, or ask for changes.
 */
class FreelanceVerificationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly FreelancerVerification $verification,
        private readonly FreelanceDocumentRequirements $requirements,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-verifications', [
            ['view', ['index', 'show', 'file', 'options', 'guide']],
            ['update', ['decide']],
        ]);
    }

    /** GET freelance-verifications[?status=&country_id=&search=&per_page=] — the oldest waiting first; `pagination.counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([VRequest::SUBMITTED, VRequest::APPROVED, VRequest::REJECTED, VRequest::CHANGES_REQUESTED])],
            'country_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = VRequest::query()->with('freelancer')
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['country_id']), fn ($q) => $q->whereHas('freelancer', fn ($w) => $w->where('country_id', $f['country_id'])))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->whereHas('freelancer', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('email', 'like', $term)));
            })
            ->orderByRaw("status = 'submitted' desc")->orderBy('submitted_at')
            ->paginate((int) ($f['per_page'] ?? 15));
        $counts = VRequest::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return ApiResponse::success(
            $page->getCollection()->map(fn (VRequest $r) => $this->presentRow($r))->values(),
            __('api.retrieved'), 200,
            ApiPaginator::meta($page) + ['counts' => array_merge(array_fill_keys([VRequest::SUBMITTED, VRequest::APPROVED, VRequest::REJECTED, VRequest::CHANGES_REQUESTED], 0), $counts)],
        );
    }

    /** The Word file that explains this page (Arabic): the statuses, how to review, what the gate does. */
    public function guide(VerificationGuides $guides): BinaryFileResponse
    {
        return response()->download($guides->verifications(), 'freelance-verification-requests.docx')->deleteFileAfterSend(true);
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
        ], __('api.retrieved'));
    }

    public function show(string $uuid, ?string $message = null): JsonResponse
    {
        $request = VRequest::query()->where('uuid', $uuid)->with('freelancer')->firstOrFail();
        $freelancer = $request->freelancer;
        $current = $this->verification->currentDocuments($freelancer);
        $required = $this->requirements->for($freelancer)->pluck('id')->all();

        return ApiResponse::success($this->presentRow($request) + [
            'freelancer' => [
                'id' => $freelancer->id, 'name' => $freelancer->name, 'phone' => $freelancer->phone, 'email' => $freelancer->email,
                'country_id' => $freelancer->country_id, 'residency' => $freelancer->residency, 'status' => $freelancer->status?->value,
                'avatar_url' => $freelancer->getFirstMediaUrl('avatar') ?: null, 'created_at' => $freelancer->created_at?->toIso8601String(),
            ],
            'documents' => $current->values()->map(fn (FreelanceVerificationDocument $d) => $this->verification->presentDocument($d, "freelance-verifications/documents") + ['required' => in_array($d->document_type_id, $required, true)]),
            'missing_types' => $this->requirements->for($freelancer)->reject(fn ($t) => $current->has($t->id))->map(fn ($t) => $this->verification->presentType($t))->values(),
            'history' => VRequest::query()->where('freelancer_id', $freelancer->id)->whereKeyNot($request->id)->latest('id')->get()->map(fn (VRequest $r) => $this->presentRow($r))->values(),
        ], $message ?? __('api.retrieved'));
    }

    /** GET freelance-verifications/documents/{id}/{side} — opens a freelancer's document for the reviewer. */
    public function file(int $document, string $side): BinaryFileResponse
    {
        abort_unless(in_array($side, FreelanceVerificationDocument::SIDES, true), 404);
        $media = FreelanceVerificationDocument::query()->findOrFail($document)->file($side);
        abort_if($media === null, 404);

        // An identity document: no shared cache may keep it.
        return tap(response()->file($media->getPath(), ['Content-Type' => $media->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** POST freelance-verifications/{uuid}/decide {decision: approve|reject|changes, reason?, documents?: {id: {status, reason?}}} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject', 'changes'])],
            'reason' => ['nullable', 'string', 'max:500'],
            'documents' => ['nullable', 'array'],
            'documents.*.status' => ['required', Rule::in([FreelanceVerificationDocument::APPROVED, FreelanceVerificationDocument::REJECTED])],
            'documents.*.reason' => ['nullable', 'string', 'max:500'],
        ]);
        $vr = VRequest::query()->where('uuid', $uuid)->firstOrFail();
        $this->verification->decide($vr, $data['decision'], $data['reason'] ?? null, $data['documents'] ?? [], (int) $request->user('admin_api')->getKey());

        return $this->show($uuid, __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function presentRow(VRequest $r): array
    {
        return [
            'uuid' => $r->uuid,
            'status' => $r->status,
            'reason' => $r->reason,
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'freelancer_name' => $r->freelancer?->name,
            'freelancer_phone' => $r->freelancer?->phone,
            'country_id' => $r->freelancer?->country_id,
        ];
    }
}
