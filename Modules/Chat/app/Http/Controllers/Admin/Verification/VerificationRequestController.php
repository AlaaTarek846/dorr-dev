<?php

namespace Modules\Chat\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Chat\Enums\VerificationActivationTiming;
use Modules\Chat\Enums\VerificationBadgeStatus;
use Modules\Chat\Enums\VerificationRequestStatus;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationRequest;
use Modules\Chat\Models\Verification\ChatVerificationRequestDocument;
use Modules\Chat\Services\Verification\VerificationBadgeService;
use Modules\Chat\Services\Verification\VerificationRequestPresenter;
use Modules\Chat\Services\Verification\VerificationRequestService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Chat → Verification → Requests (AV-015 – AV-018, AV-090): the list of both tracks, one request with its
 * documents and trail, and the decisions. Reviewing (`requests.review`) is kept apart from approving
 * (`requests.approve`, REQ-09-03); each route is checked on the server and a refused attempt is logged.
 */
class VerificationRequestController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly VerificationRequestService $requests,
        private readonly VerificationRequestPresenter $presenter,
    ) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return [...EnsureVerificationPermission::map('chat-verification-requests', [
            ['view', ['index', 'show', 'file']],
            ['review', ['startReview', 'reviewDocument', 'checkIdentity', 'requestCompletion', 'extendDeadline', 'pauseDeadline', 'resumeDeadline', 'resume']],
            ['approve', ['approve', 'reject', 'expire', 'cancel']],
            ['early-activate', ['earlyActivate']],
        ]), ...EnsureVerificationPermission::map('chat-verification-badges', [
            ['update', ['setTiming']],
        ])];
    }

    /** GET chat-verification/requests[?status=&track=&search=&overdue=1&per_page=] — the nearest deadline first; `pagination.counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([...VerificationRequestStatus::values(), 'open'])],
            'track' => ['nullable', Rule::in(VerificationTrack::values())],
            'search' => ['nullable', 'string', 'max:100'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ChatVerificationRequest::query()->with('user')
            ->when(($f['status'] ?? null) === 'open', fn ($q) => $q->whereIn('status', VerificationRequestStatus::openValues()))
            ->when(isset($f['status']) && $f['status'] !== 'open', fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['track']), fn ($q) => $q->where('track', $f['track']))
            ->when(! empty($f['overdue']), fn ($q) => $q->whereNotNull('deadline_at')->where('deadline_at', '<=', now()))
            ->when(isset($f['search']), function ($q) use ($f) {
                $term = '%'.addcslashes($f['search'], '%_\\').'%';
                $q->where(fn ($w) => $w->where('full_name', 'like', $term)->orWhere('contact_phone', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->orderByRaw('deadline_at is null')->orderBy('deadline_at')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 15));

        $counts = ChatVerificationRequest::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all();

        return ApiResponse::success(
            $page->getCollection()->map(fn (ChatVerificationRequest $r) => $this->presenter->row($r))->values(),
            __('api.retrieved'), 200,
            ApiPaginator::meta($page) + ['counts' => array_merge(array_fill_keys(VerificationRequestStatus::values(), 0), $counts)],
        );
    }

    public function show(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $verificationRequest, __('api.retrieved'));
    }

    /** GET chat-verification/requests/{uuid}/documents/{document}/{side} — shown inline, never cached. */
    public function file(ChatVerificationRequest $verificationRequest, int $document, string $side): BinaryFileResponse
    {
        $doc = ChatVerificationRequestDocument::query()->where('request_id', $verificationRequest->id)->find($document);
        $media = in_array($side, ChatVerificationRequestDocument::SIDES, true) ? $doc?->file($side) : null;
        if ($media === null) {
            throw new ChatException('verification_document_not_found', 404);
        }

        return tap(response()->file($media->getPath(), ['Content-Type' => $media->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    public function startReview(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->startReview($this->admin($request), $verificationRequest));
    }

    /** POST …/documents/{document}/review {accepted, note?} — a note is needed to turn one down. */
    public function reviewDocument(Request $request, ChatVerificationRequest $verificationRequest, int $document): JsonResponse
    {
        $data = $request->validate(['accepted' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);

        return $this->answer($request, $this->requests->reviewDocument($this->admin($request), $verificationRequest, $document, (bool) $data['accepted'], $data['note'] ?? null));
    }

    public function checkIdentity(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->checkIdentity($this->admin($request), $verificationRequest));
    }

    /** POST …/request-completion {reason, document_type_ids[]?} */
    public function requestCompletion(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'document_type_ids' => ['nullable', 'array'],
            'document_type_ids.*' => ['integer', 'distinct', Rule::exists('chat_verification_document_types', 'id')],
        ]);

        return $this->answer($request, $this->requests->requestCompletion($this->admin($request), $verificationRequest, $data['reason'], $data['document_type_ids'] ?? []));
    }

    /** POST …/approve {reason?} */
    public function approve(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->answer($request, $this->requests->approve($this->admin($request), $verificationRequest, $data['reason'] ?? null));
    }

    /** POST …/reject {reason} */
    public function reject(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->reject($this->admin($request), $verificationRequest, $this->reason($request)));
    }

    /** POST …/deadline {until, reason} */
    public function extendDeadline(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        $data = $request->validate(['until' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']]);

        return $this->answer($request, $this->requests->extendDeadline($this->admin($request), $verificationRequest, Carbon::parse($data['until']), $data['reason']));
    }

    /** POST …/deadline/pause {reason} */
    public function pauseDeadline(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->pauseDeadline($this->admin($request), $verificationRequest, $this->reason($request)));
    }

    /** POST …/deadline/resume {reason?} */
    public function resumeDeadline(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return $this->answer($request, $this->requests->resumeDeadline($this->admin($request), $verificationRequest, $data['reason'] ?? null));
    }

    /** POST …/resume {until, reason} — a request on hold goes back where it was. */
    public function resume(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        $data = $request->validate(['until' => ['required', 'date'], 'reason' => ['required', 'string', 'max:1000']]);

        return $this->answer($request, $this->requests->resumeSuspended($this->admin($request), $verificationRequest, Carbon::parse($data['until']), $data['reason']));
    }

    /** POST …/expire {reason} */
    public function expire(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->closeExpired($this->admin($request), $verificationRequest, $this->reason($request)));
    }

    /** POST …/cancel {reason} */
    public function cancel(Request $request, ChatVerificationRequest $verificationRequest): JsonResponse
    {
        return $this->answer($request, $this->requests->cancelByStaff($this->admin($request), $verificationRequest, $this->reason($request)));
    }

    /** POST …/early-activate {status: temporary|active, reason} — only after the identity check (AT-11). */
    public function earlyActivate(Request $request, ChatVerificationRequest $verificationRequest, VerificationBadgeService $badges): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['temporary', 'active'])], 'reason' => ['required', 'string', 'max:1000']]);
        $badges->earlyActivate($this->admin($request), $verificationRequest->load('user'), VerificationBadgeStatus::from($data['status']), $data['reason']);

        return $this->answer($request, $verificationRequest);
    }

    /** POST …/activation-timing {timing, reason} — when this request's badge turns on. */
    public function setTiming(Request $request, ChatVerificationRequest $verificationRequest, VerificationBadgeService $badges): JsonResponse
    {
        $data = $request->validate(['timing' => ['required', Rule::in(VerificationActivationTiming::values())], 'reason' => ['required', 'string', 'max:1000']]);
        $badges->setTiming($this->admin($request), $verificationRequest, VerificationActivationTiming::from($data['timing']), $data['reason']);

        return $this->answer($request, $verificationRequest);
    }

    private function answer(Request $request, ChatVerificationRequest $r, ?string $message = null): JsonResponse
    {
        return ApiResponse::success($this->presenter->forStaff($r->fresh(), $this->admin($request)), $message ?? __('api.updated'));
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];
    }

    private function admin(Request $request): Admin
    {
        return $request->user('admin_api');
    }
}
