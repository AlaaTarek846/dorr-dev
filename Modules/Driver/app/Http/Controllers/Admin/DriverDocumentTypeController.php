<?php

namespace Modules\Driver\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\ServiceCategory;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Driver\Enums\DocumentSubject;
use Modules\Driver\Enums\DriverResidency;
use Modules\Driver\Exceptions\DriverException;
use Modules\Driver\Models\DriverDocument;
use Modules\Driver\Models\DriverDocumentRequirement;
use Modules\Driver\Models\DriverDocumentType;

/**
 * Admin → Drivers → Document types (Jawad's documents screen): add a type, switch it off, and say
 * where it is required — the country, the DORR service (or any) and from whom: citizens, residents
 * or both. A type switched off is required from nobody; a type already uploaded can't be deleted.
 */
class DriverDocumentTypeController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('driver-document-types', [
            ['view', ['index', 'options']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $types = DriverDocumentType::query()->with(['translations', 'requirements'])->withCount('documents')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (DriverDocumentType $t) => $this->present($t))->values(), __('api.retrieved'));
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'services' => ServiceCategory::query()->with('translations')->get()
                ->map(fn (ServiceCategory $s) => ['id' => $s->id, 'module_name' => $s->module_name, 'name' => $s->translatedName() ?? $s->module_name])->values(),
            'residencies' => DriverResidency::values(),
            'applies_to' => DocumentSubject::values(),
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::transaction(function () use ($data) {
            $type = new DriverDocumentType(collect($data)->except(['name', 'instructions', 'requirements'])->all());
            $type->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
            $this->syncRequirements($type, $data['requirements'] ?? []);

            return $type;
        });
        $audit->record('driver_document_type.created', $type, null, $data);

        return ApiResponse::success($this->present($type->fresh(['translations', 'requirements'])), __('api.created'), 201);
    }

    public function update(Request $request, DriverDocumentType $documentType, AuditLogger $audit): JsonResponse
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
        $audit->record('driver_document_type.updated', $documentType, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    /** On / off: a type that is off is required from nobody (the work gate skips it). */
    public function status(Request $request, DriverDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $documentType->update(['status' => (bool) $data['status']]);
        $audit->record('driver_document_type.status', $documentType, null, ['status' => (bool) $data['status']]);

        return ApiResponse::success($this->present($documentType->fresh(['translations', 'requirements'])), __('api.updated'));
    }

    public function destroy(DriverDocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        if (DriverDocument::query()->where('document_type_id', $documentType->id)->exists()) {
            // Uploaded documents keep their type: switch it off instead.
            throw new DriverException('document_type_in_use', 409);
        }
        $documentType->delete();
        $audit->record('driver_document_type.deleted', $documentType);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @param list<array{country_id: int, service_category_id?: ?int, residency?: ?string, is_required?: bool}> $rows */
    private function syncRequirements(DriverDocumentType $type, array $rows): void
    {
        DriverDocumentRequirement::query()->where('document_type_id', $type->id)->delete();
        foreach (collect($rows)->unique(fn ($r) => ($r['country_id']).'|'.($r['service_category_id'] ?? '').'|'.($r['residency'] ?? '')) as $row) {
            DriverDocumentRequirement::query()->create([
                'document_type_id' => $type->id,
                'country_id' => $row['country_id'],
                'service_category_id' => $row['service_category_id'] ?? null,
                'residency' => $row['residency'] ?? null,
                'is_required' => $row['is_required'] ?? true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DriverDocumentType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('driver_document_types', 'code')->ignore($type?->id)],
            'applies_to' => ['required', Rule::enum(DocumentSubject::class)],
            'has_number' => ['boolean'],
            'has_expiry' => ['boolean'],
            'needs_back_side' => ['boolean'],
            'expiry_alert_days' => ['nullable', 'integer', 'between:0,365'],
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
            'requirements.*.service_category_id' => ['nullable', 'integer', Rule::exists('service_categories', 'id')],
            'requirements.*.residency' => ['nullable', Rule::enum(DriverResidency::class)],
            'requirements.*.is_required' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DriverDocumentType $type): array
    {
        $translation = fn (string $field) => $type->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $type->id,
            'code' => $type->code,
            'name' => $type->translated('name'),
            'names' => $translation('name'),
            'instructions' => $translation('instructions'),
            'applies_to' => $type->applies_to->value,
            'has_number' => $type->has_number,
            'has_expiry' => $type->has_expiry,
            'needs_back_side' => $type->needs_back_side,
            'expiry_alert_days' => $type->expiry_alert_days,
            'status' => (bool) $type->status,
            'sort_order' => $type->sort_order,
            'documents_count' => $type->documents_count ?? null,
            'requirements' => $type->requirements->map(fn (DriverDocumentRequirement $r) => [
                'id' => $r->id, 'country_id' => $r->country_id, 'service_category_id' => $r->service_category_id,
                'residency' => $r->residency?->value, 'is_required' => (bool) $r->is_required,
            ])->values(),
        ];
    }
}
