<?php

namespace Modules\Chat\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;

/**
 * Admin → Chat → Verification → Documents (AV-003, REQ-01-05): what a request asks the account to
 * upload, per track — the ID (front and back), a selfie holding it, proof a celebrity owns their
 * accounts… A document switched off is asked of nobody. Every change goes to the audit log.
 */
class VerificationDocumentTypeController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return EnsureVerificationPermission::map('chat-verification-settings', [
            ['view', ['index']],
            ['update', ['store', 'update', 'status', 'destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $types = ChatVerificationDocumentType::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success([
            'items' => $types->map(fn (ChatVerificationDocumentType $t) => $this->present($t))->values(),
            'tracks' => VerificationTrack::values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::transaction(function () use ($data) {
            $type = new ChatVerificationDocumentType(collect($data)->except(['name', 'instructions'])->all());
            $type->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();

            return $type;
        });
        $after = $this->present($type->fresh('translations'));
        $audit->record('chat_verification.document_type_created', $type, null, $after);

        return ApiResponse::success($after, __('api.created'), 201);
    }

    public function update(Request $request, ChatVerificationDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $documentType);
        $before = $this->present($documentType->load('translations'));
        DB::transaction(function () use ($documentType, $data) {
            $documentType->fill(collect($data)->except(['name', 'instructions'])->all());
            $documentType->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
        });
        $after = $this->present($documentType->fresh('translations'));
        $audit->record('chat_verification.document_type_updated', $documentType, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    public function status(Request $request, ChatVerificationDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $before = ['status' => (bool) $documentType->status];
        $documentType->update(['status' => (bool) $data['status']]);
        $audit->record('chat_verification.document_type_status', $documentType, $before, ['status' => (bool) $data['status']]);

        return ApiResponse::success($this->present($documentType->fresh('translations')), __('api.updated'));
    }

    /** Soft-deleted: a request that already holds this document still shows what it was. */
    public function destroy(ChatVerificationDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $before = $this->present($documentType->load('translations'));
        $documentType->delete();
        $audit->record('chat_verification.document_type_deleted', $documentType, $before);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ChatVerificationDocumentType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('chat_verification_document_types', 'code')->ignore($type?->id)],
            'tracks' => ['required', 'array', 'min:1'],
            'tracks.*' => ['string', 'distinct', Rule::in(VerificationTrack::values())],
            'is_required' => ['boolean'],
            'needs_back_side' => ['boolean'],
            'status' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['required', 'string', 'max:191'],
            'instructions' => ['nullable', 'array'],
            'instructions.ar' => ['nullable', 'string', 'max:1000'],
            'instructions.en' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ChatVerificationDocumentType $type): array
    {
        $translation = fn (string $field) => $type->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->translated('name'),
            'names' => $translation('name'),
            'instructions' => $translation('instructions'),
            'tracks' => array_values($type->tracks ?? []),
            'is_required' => (bool) $type->is_required,
            'needs_back_side' => (bool) $type->needs_back_side,
            'status' => (bool) $type->status,
            'sort_order' => (int) $type->sort_order,
        ];
    }
}
