<?php

namespace Modules\Provider\Http\Controllers\Admin;

use App\Enums\ServiceAudience;
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
use Modules\Provider\Enums\DocumentKind;
use Modules\Provider\Enums\DocumentSubject;
use Modules\Provider\Enums\EstablishmentType;
use Modules\Provider\Exceptions\ProviderException;
use Modules\Provider\Models\DocumentRequirement;
use Modules\Provider\Models\DocumentType;
use Modules\Provider\Models\ProviderDocument;
use Modules\Provider\Models\VehicleType;

/**
 * Admin → Providers → Documents asked (A-11): papers and vehicle photos, and where each is required — the country,
 * the service (or any), the vehicle type (or any) and the establishment type (or both). Same idea as the captains'
 * document types. The vehicle-with-plate photo is always asked and can't be switched off or deleted.
 */
class ProviderDocumentTypeAdminController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('provider-document-types', [
            ['view', ['index', 'options']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $types = DocumentType::query()->with(['translations', 'requirements'])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($types->map(fn (DocumentType $t) => $this->present($t))->values(), __('api.retrieved'));
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'countries' => Country::query()->with('translations')->where('status', true)->get()
                ->map(fn (Country $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->translatedName() ?? $c->code])->values(),
            'services' => ServiceCategory::query()->with('translations')->whereJsonContains('audiences', ServiceAudience::Provider->value)->orderBy('sort_order')->get()
                ->map(fn (ServiceCategory $s) => ['id' => $s->id, 'module_name' => $s->module_name, 'name' => $s->translatedName() ?? $s->module_name])->values(),
            'vehicle_types' => VehicleType::query()->with('translations')->orderBy('sort_order')->get()
                ->map(fn (VehicleType $v) => ['id' => $v->id, 'code' => $v->code, 'name' => $v->translatedName() ?? $v->code])->values(),
            'subjects' => array_column(DocumentSubject::cases(), 'value'),
            'kinds' => array_column(DocumentKind::cases(), 'value'),
            'establishment_types' => array_column(EstablishmentType::cases(), 'value'),
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $type = DB::transaction(function () use ($data) {
            $type = new DocumentType(collect($data)->except(['name', 'instructions', 'requirements'])->all());
            $type->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
            $this->syncRequirements($type, $data['requirements'] ?? []);

            return $type;
        });
        $audit->record('provider_document_type.created', $type, null, $data);

        return ApiResponse::success($this->present($type->fresh(['translations', 'requirements'])), __('api.created'), 201);
    }

    public function update(Request $request, DocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $documentType);
        if ($documentType->code === DocumentType::PLATE_PHOTO) {
            // Always asked (owner's call): its code, subject and on/off stay as they are.
            $data = ['code' => DocumentType::PLATE_PHOTO, 'subject' => DocumentSubject::Vehicle->value, 'kind' => DocumentKind::Photo->value, 'status' => true] + $data;
        }
        $before = $this->present($documentType->load(['translations', 'requirements']));
        DB::transaction(function () use ($documentType, $data) {
            $documentType->fill(collect($data)->except(['name', 'instructions', 'requirements'])->all());
            $documentType->fillAllTranslations(['name' => $data['name'], 'instructions' => $data['instructions'] ?? []])->save();
            if (array_key_exists('requirements', $data)) {
                $this->syncRequirements($documentType, $data['requirements'] ?? []);
            }
        });
        $after = $this->present($documentType->fresh(['translations', 'requirements']));
        $audit->record('provider_document_type.updated', $documentType, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    public function destroy(DocumentType $documentType, AuditLogger $audit): JsonResponse
    {
        if ($documentType->code === DocumentType::PLATE_PHOTO
            || ProviderDocument::query()->where('document_type_id', $documentType->id)->exists()) {
            // Uploaded documents keep their type: switch it off instead.
            throw new ProviderException('document_type_in_use', 409);
        }
        $documentType->delete();
        $audit->record('provider_document_type.deleted', $documentType);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @param list<array<string, mixed>> $rows */
    private function syncRequirements(DocumentType $type, array $rows): void
    {
        DocumentRequirement::query()->where('document_type_id', $type->id)->delete();
        $unique = collect($rows)->unique(fn ($r) => implode('|', [$r['country_id'], $r['service_category_id'] ?? '', $r['vehicle_type_id'] ?? '', $r['establishment_type'] ?? '']));
        foreach ($unique as $row) {
            DocumentRequirement::query()->create([
                'document_type_id' => $type->id,
                'country_id' => $row['country_id'],
                'service_category_id' => $row['service_category_id'] ?? null,
                'vehicle_type_id' => $row['vehicle_type_id'] ?? null,
                'establishment_type' => $row['establishment_type'] ?? null,
                'is_required' => $row['is_required'] ?? true,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?DocumentType $type = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('provider_document_types', 'code')->ignore($type?->id)],
            'subject' => ['required', Rule::enum(DocumentSubject::class)],
            'kind' => ['required', Rule::enum(DocumentKind::class)],
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
            'requirements.*.vehicle_type_id' => ['nullable', 'integer', Rule::exists('provider_vehicle_types', 'id')],
            'requirements.*.establishment_type' => ['nullable', Rule::enum(EstablishmentType::class)],
            'requirements.*.is_required' => ['nullable', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(DocumentType $type): array
    {
        $translation = fn (string $field) => $type->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $type->id,
            'code' => $type->code,
            'locked' => $type->code === DocumentType::PLATE_PHOTO,
            'name' => $type->translated('name'),
            'names' => $translation('name'),
            'instructions' => $translation('instructions'),
            'subject' => $type->subject->value,
            'kind' => $type->kind->value,
            'has_number' => $type->has_number,
            'has_expiry' => $type->has_expiry,
            'needs_back_side' => $type->needs_back_side,
            'expiry_alert_days' => $type->expiry_alert_days,
            'status' => (bool) $type->status,
            'sort_order' => $type->sort_order,
            'requirements' => $type->requirements->map(fn (DocumentRequirement $r) => [
                'id' => $r->id, 'country_id' => $r->country_id, 'service_category_id' => $r->service_category_id,
                'vehicle_type_id' => $r->vehicle_type_id, 'establishment_type' => $r->establishment_type, 'is_required' => (bool) $r->is_required,
            ])->values(),
        ];
    }
}
