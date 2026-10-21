<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceDocumentRequirement;
use Modules\Freelance\Models\FreelanceDocumentType;
use Modules\Freelance\Models\FreelanceVerificationDocument;
use Modules\Freelance\Services\Verification\VerificationGuides;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin → Freelance → Verification documents (FL-107): the kinds of identity document freelancers upload, and where each
 * is required — the country, and from whom (citizens, residents or both). A type switched off is required from nobody;
 * a type already uploaded can't be deleted.
 */
class FreelanceDocumentTypeController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-document-types', [
            ['view', ['index', 'options', 'guide']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $types = FreelanceDocumentType::query()->with(['translations', 'requirements'])->withCount('documents')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (FreelanceDocumentType $t) => $this->present($t))->values(), __('api.retrieved'));
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'residencies' => FreelanceDocumentRequirement::RESIDENCIES,
        ], __('api.retrieved'));
    }

    /** The Word file that explains this page (Arabic), with what the chosen country requires. */
    public function guide(Request $request, VerificationGuides $guides): BinaryFileResponse
    {
        $data = $request->validate(['country_id' => ['required', 'integer', Rule::exists('countries', 'id')]]);
        $path = $guides->documentTypes(Country::query()->with('translations')->findOrFail($data['country_id']));

        return response()->download($path, 'freelance-verification-documents.docx')->deleteFileAfterSend(true);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::transaction(function () use ($data) {
            $type = new FreelanceDocumentType(collect($data)->except(['name', 'instructions', 'requirements'])->all());
            $type->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
            $this->syncRequirements($type, $data['requirements'] ?? []);

            return $type;
        });
        $audit->record('freelance_document_type.created', $type, null, $data);

        return ApiResponse::success($this->present($type->fresh(['translations', 'requirements'])), __('api.created'), 201);
    }

    public function update(Request $request, FreelanceDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $documentType);
        $before = $this->present($documentType->load(['translations', 'requirements']));
        DB::transaction(function () use ($documentType, $data) {
            $documentType->fill(collect($data)->except(['name', 'instructions', 'requirements'])->all());
            $documentType->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
            if (array_key_exists('requirements', $data)) {
                $this->syncRequirements($documentType, $data['requirements'] ?? []);
            }
        });
        $after = $this->present($documentType->fresh(['translations', 'requirements']));
        $audit->record('freelance_document_type.updated', $documentType, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    public function status(Request $request, FreelanceDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $documentType->update(['status' => (bool) $data['status']]);
        $audit->record('freelance_document_type.status', $documentType, null, ['status' => (bool) $data['status']]);

        return ApiResponse::success($this->present($documentType->fresh(['translations', 'requirements'])), __('api.updated'));
    }

    public function destroy(FreelanceDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        if (FreelanceVerificationDocument::query()->where('document_type_id', $documentType->id)->exists()) {
            throw new FreelanceException('document_type_in_use', 409);
        }
        $documentType->delete();
        $audit->record('freelance_document_type.deleted', $documentType);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @param  list<array{country_id: int, residency?: ?string, is_required?: bool}>  $rows */
    private function syncRequirements(FreelanceDocumentType $type, array $rows): void
    {
        FreelanceDocumentRequirement::query()->where('document_type_id', $type->id)->delete();
        foreach (collect($rows)->unique(fn ($r) => $r['country_id'].'|'.($r['residency'] ?? '')) as $row) {
            FreelanceDocumentRequirement::query()->create([
                'document_type_id' => $type->id,
                'country_id' => $row['country_id'],
                'residency' => $row['residency'] ?? null,
                'is_required' => $row['is_required'] ?? true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FreelanceDocumentType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('freelance_document_types', 'code')->ignore($type?->id)],
            'has_number' => ['boolean'],
            'has_expiry' => ['boolean'],
            'needs_back_side' => ['boolean'],
            'status' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['required', 'string', 'max:191'],
            'instructions' => ['nullable', 'array'],
            'instructions.ar' => ['nullable', 'string', 'max:1000'],
            'instructions.en' => ['nullable', 'string', 'max:1000'],
            'requirements' => ['sometimes', 'array'],
            'requirements.*.country_id' => ['required', 'integer', Rule::exists('countries', 'id')],
            'requirements.*.residency' => ['nullable', Rule::in(FreelanceDocumentRequirement::RESIDENCIES)],
            'requirements.*.is_required' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(FreelanceDocumentType $type): array
    {
        $translation = fn (string $field) => $type->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->translated('name'),
            'names' => $translation('name'),
            'instructions' => $translation('instructions'),
            'has_number' => $type->has_number,
            'has_expiry' => $type->has_expiry,
            'needs_back_side' => $type->needs_back_side,
            'status' => (bool) $type->status,
            'sort_order' => $type->sort_order,
            'documents_count' => $type->documents_count ?? null,
            'requirements' => $type->requirements->map(fn (FreelanceDocumentRequirement $r) => ['id' => $r->id, 'country_id' => $r->country_id, 'residency' => $r->residency, 'is_required' => (bool) $r->is_required])->values(),
        ];
    }
}
