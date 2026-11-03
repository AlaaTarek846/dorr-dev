<?php

namespace Modules\Chat\Http\Controllers\General\Verification;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\Chat\Models\Verification\ChatVerificationRequest;
use Modules\Chat\Models\Verification\ChatVerificationRequestDocument;
use Modules\Chat\Services\Verification\VerificationRequestPresenter;
use Modules\Chat\Services\Verification\VerificationRequestService;
use Modules\Freelance\Rules\CleanUpload;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The account's verification request (AV-011 – AV-014, AV-019): send it with its documents, follow it,
 * send what was asked for, cancel it, and open its own files. All under `chat/verification/request`.
 */
class VerificationRequestController extends Controller
{
    public function __construct(
        private readonly VerificationRequestService $requests,
        private readonly VerificationRequestPresenter $presenter,
    ) {}

    /** GET verification/request — the latest request (open or closed), or `data: null`. */
    public function show(Request $request): JsonResponse
    {
        $current = $this->requests->current($request->user());

        return ApiResponse::success($current === null ? null : $this->presenter->forAccount($current), __('api.retrieved'));
    }

    /** GET verification/badge — my badge (AV-024): status, type, since when and until when; `data: null` = never verified. */
    public function badge(Request $request): JsonResponse
    {
        $badge = ChatVerificationBadge::query()->where('user_id', $request->user()->id)->first();

        return ApiResponse::success($this->presenter->badge($badge), __('api.retrieved'));
    }

    /** POST verification/request (multipart) {track, full_name, contact_phone?, contact_email?, notes?, documents[type_id][front|back]} */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'track' => ['required', Rule::in(VerificationTrack::values())],
            'full_name' => ['required', 'string', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ] + $this->fileRules());

        $created = $this->requests->submit($request->user(), $data, $this->files($request));

        return ApiResponse::success($this->presenter->forAccount($created->refresh()), __('api.created'), 201);
    }

    /** POST verification/request/{uuid}/complete (multipart) {full_name?, contact_phone?, contact_email?, note?, documents[type_id][front|back]} */
    public function complete(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:191'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'contact_email' => ['nullable', 'email', 'max:191'],
            'note' => ['nullable', 'string', 'max:2000'],
        ] + $this->fileRules());

        $done = $this->requests->complete($request->user(), $this->own($request, $uuid), $data, $this->files($request));

        return ApiResponse::success($this->presenter->forAccount($done), __('api.updated'));
    }

    /** POST verification/request/{uuid}/cancel {reason?} */
    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $done = $this->requests->cancelByAccount($request->user(), $this->own($request, $uuid), $data['reason'] ?? null);

        return ApiResponse::success($this->presenter->forAccount($done), __('api.updated'));
    }

    /** GET verification/request/{uuid}/documents/{document}/{side} — one of the account's own files. */
    public function file(Request $request, string $uuid, int $document, string $side): BinaryFileResponse
    {
        $owner = $this->own($request, $uuid);
        $doc = ChatVerificationRequestDocument::query()->where('request_id', $owner->id)->find($document);
        $media = in_array($side, ChatVerificationRequestDocument::SIDES, true) ? $doc?->file($side) : null;
        if ($media === null) {
            throw new ChatException('verification_document_not_found', 404);
        }

        return tap(response()->download($media->getPath(), $media->file_name), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    private function own(Request $request, string $uuid): ChatVerificationRequest
    {
        return ChatVerificationRequest::query()->where('uuid', $uuid)->where('user_id', $request->user()->id)->first()
            ?? throw new ChatException('verification_request_not_found', 404);
    }

    /** @return array<string, list<mixed>> */
    private function fileRules(): array
    {
        $file = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.(int) config('chat.verification.max_file_kb', 8192)];
        // The freelance module's scanner checks the real bytes when it is installed.
        if (class_exists(CleanUpload::class)) {
            $file[] = new CleanUpload;
        }

        return [
            'documents' => ['sometimes', 'array', 'max:20'],
            'documents.*' => ['array'],
            'documents.*.front' => ['required_with:documents.*', ...$file],
            'documents.*.back' => ['nullable', ...$file],
        ];
    }

    /** @return array<int, array<string, UploadedFile>> */
    private function files(Request $request): array
    {
        $out = [];
        foreach ((array) $request->file('documents', []) as $typeId => $sides) {
            if (is_array($sides) && is_numeric($typeId)) {
                $out[(int) $typeId] = array_filter(['front' => $sides['front'] ?? null, 'back' => $sides['back'] ?? null]);
            }
        }

        return $out;
    }
}
