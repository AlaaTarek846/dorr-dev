<?php

namespace Modules\AI\Http\Controllers\Ar;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArAsset;
use Modules\AI\Models\Ar\AiArExperience;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\Ar\AiArAssetService;
use Modules\AI\Models\Ar\AiArCapture;
use Modules\AI\Models\Ar\AiArApproval;
use Modules\AI\Services\Ar\AiArApprovalService;
use Modules\AI\Services\Ar\AiArCapabilityService;
use Modules\AI\Services\Ar\AiArCaptureService;
use Modules\AI\Services\Ar\AiArVisionService;
use Modules\AI\Services\Ar\AiArCommandInterpreter;
use Modules\AI\Services\Ar\AiArExperienceService;

/** Customer side of the AR engine, shared by the user and provider apps. Every lookup is scoped to the caller. */
class AiArController extends Controller
{
    public function __construct(
        protected AiArAssetService $assets,
        protected AiArExperienceService $experiences,
        protected AiArCapabilityService $capability,
    ) {}

    public function info()
    {
        $s = AiArSetting::current();

        return ApiResponse::success([
            'enabled' => $s->is_enabled,
            'types' => AiArExperience::TYPES,
            'limits' => ['max_asset_mb' => $s->max_asset_mb, 'max_triangles' => $s->max_triangles],
            'measurement_notice' => __('ai.ar_measurement_notice'),
            'notices' => collect(AiArExperience::TYPES)->mapWithKeys(fn ($t) => [$t => $this->noticeFor($t)])->filter()->all(),
        ]);
    }

    public function capability(Request $request)
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'max:10'], 'manufacturer' => ['nullable', 'string', 'max:60'], 'model' => ['nullable', 'string', 'max:120'],
            'arcore_supported' => ['required', 'boolean'], 'depth_supported' => ['nullable', 'boolean'],
        ]);

        return ApiResponse::success($this->capability->evaluate($data));
    }

    public function assetsIndex(Request $request)
    {
        $items = $this->assets->visibleTo($request->user())
            ->when($request->query('category'), fn ($q, $c) => $q->where('category', $c))
            ->when($request->query('q'), fn ($q, $term) => $q->where(fn ($w) => $w->where('name_en', 'like', '%'.$term.'%')->orWhere('name_ar', 'like', '%'.$term.'%')))
            ->with('currentVersion')->orderBy('name_en')->limit(60)->get();

        return ApiResponse::success($items->map(fn ($a) => $this->presentAsset($a))->all());
    }

    public function assetShow(Request $request, int $id)
    {
        $asset = AiArAsset::query()->with('currentVersion')->find($id);

        if ($asset === null || ! $this->assets->canView($request->user(), $asset)) {
            throw new AiArException('not_found', 404);
        }

        return ApiResponse::success($this->presentAsset($asset) + ['download_url' => URL::temporarySignedRoute('api.ai-ar.asset-file', now()->addMinutes(10), ['id' => $asset->id, 'v' => $asset->current_version_id])]);
    }

    /** Signed, short-lived link: the model file itself is never on a public path. */
    public function assetFile(Request $request, int $id)
    {
        $version = AiArAsset::query()->findOrFail($id)->versions()->findOrFail((int) $request->query('v'));

        return Storage::disk('local')->download($version->glb_path, 'model.glb', ['Content-Type' => 'model/gltf-binary']);
    }

    public function storeAsset(Request $request)
    {
        $data = $request->validate(['name_en' => ['required', 'string', 'max:191'], 'name_ar' => ['nullable', 'string', 'max:191'], 'category' => ['required', 'string', 'max:40'], 'file' => ['required', 'file']]);

        return ApiResponse::success($this->presentAsset($this->assets->create($data, $request->file('file'), $request->user())->load('currentVersion')), 'OK', 201);
    }

    public function experiencesIndex(Request $request)
    {
        $items = AiArExperience::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->latest('id')->limit(100)->get();

        return ApiResponse::success($items->map(fn ($e) => $this->presentExperience($e))->all());
    }

    public function storeExperience(Request $request)
    {
        $data = $request->validate(['type' => ['required', 'string'], 'title' => ['required', 'string', 'max:191'], 'source_service' => ['nullable', 'string', 'max:60']]);
        $countryId = app()->bound('resolved_country') ? app('resolved_country')?->id : null;

        return ApiResponse::success($this->presentExperience($this->experiences->create($request->user(), $countryId, $data['type'], $data['title'], $data['source_service'] ?? null)), 'OK', 201);
    }

    public function showExperience(Request $request, int $id)
    {
        $experience = $this->mine($request, $id);

        return ApiResponse::success($this->presentExperience($experience) + ['scene' => $this->presentScene($experience)]);
    }

    public function saveScene(Request $request, int $id)
    {
        $experience = $this->mine($request, $id);
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['present', 'array', 'max:60'],
            'items.*.asset_id' => ['required', 'integer'],
            'items.*.transform' => ['required', 'array'],
            'items.*.transform.position' => ['required', 'array', 'size:3'],
            'items.*.transform.position.*' => ['numeric', 'between:-1000,1000'],
            'items.*.transform.rotation' => ['required', 'array', 'size:4'],
            'items.*.transform.rotation.*' => ['numeric', 'between:-1,1'],
            'items.*.transform.scale' => ['required', 'array', 'size:3'],
            'items.*.transform.scale.*' => ['numeric', 'between:0.01,100'],
            'items.*.tint' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/'],
            'items.*.visible' => ['nullable', 'boolean'],
            'items.*.anchor_type' => ['nullable', 'in:plane,image,world'],
            'items.*.label' => ['nullable', 'string', 'max:120'],
            // What a label may carry: teaching text, a product card or a property note. Nothing else is kept.
            'items.*.info' => ['nullable', 'array'],
            'items.*.info.title' => ['nullable', 'string', 'max:120'],
            'items.*.info.description' => ['nullable', 'string', 'max:1000'],
            'items.*.info.price_minor' => ['nullable', 'integer', 'min:0'],
            'items.*.info.url' => ['nullable', 'url:https', 'max:500'],
        ]);

        $this->experiences->saveScene($request->user(), $experience, $data['items'], $data['note'] ?? null);
        $experience->refresh()->load('currentVersion.items');

        return ApiResponse::success($this->presentExperience($experience) + ['scene' => $this->presentScene($experience)]);
    }

    public function destroyExperience(Request $request, int $id)
    {
        $experience = $this->mine($request, $id);

        foreach ($experience->captures as $capture) {
            Storage::disk('local')->delete($capture->file_path);
        }

        $experience->delete();

        return ApiResponse::success(null);
    }

    /** AR-016: the "view in AR" button appears only when the service supports AR, there is content, and the device (or the 3D viewer fallback) can show it. */
    public function launchCheck(Request $request)
    {
        $data = $request->validate(['type' => ['required', 'string'], 'asset_ids' => ['nullable', 'array', 'max:20'], 'asset_ids.*' => ['integer'], 'arcore_supported' => ['nullable', 'boolean']]);

        $supported = AiArSetting::current()->is_enabled && in_array($data['type'], AiArExperience::TYPES, true);
        $hasContent = $this->assets->visibleTo($request->user())->whereIn('id', $data['asset_ids'] ?? [])->exists();

        return ApiResponse::success([
            'show' => $supported && $hasContent,
            'mode' => ($data['arcore_supported'] ?? false) ? 'ar' : 'viewer_3d',
            'reason' => ! $supported ? 'type_unsupported' : (! $hasContent ? 'no_content' : null),
        ]);
    }

    /** AR-020: free text -> whitelisted actions. The model only proposes; the device applies, and the saved scene is untouched. */
    public function command(Request $request, AiArCommandInterpreter $interpreter)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:500'],
            'items' => ['nullable', 'array', 'max:60'], 'items.*.id' => ['required', 'integer'], 'items.*.name' => ['required', 'string', 'max:120'],
        ]);

        if (! AiArSetting::current()->is_enabled) {
            throw new AiArException('disabled', 403);
        }

        $assets = $this->assets->visibleTo($request->user())->where('status', 'active')->limit(60)->get(['id', 'name_en'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name_en])->all();

        return ApiResponse::success($interpreter->interpret($data['text'], $data['items'] ?? [], $assets));
    }

    /** Photo/video taken inside an experience: private, consent required, expires on its own. */
    public function storeCapture(Request $request, int $id, AiArCaptureService $captures)
    {
        $data = $request->validate(['file' => ['required', 'file'], 'kind' => ['required', 'in:photo,video'], 'consent' => ['required', 'boolean']]);
        $capture = $captures->store($this->mine($request, $id), $request->file('file'), $data['kind'], (bool) $data['consent']);

        return ApiResponse::success(['id' => $capture->id, 'kind' => $capture->kind, 'expires_at' => $capture->expires_at], 'OK', 201);
    }

    public function destroyCapture(Request $request, int $id, int $captureId, AiArCaptureService $captures)
    {
        $capture = $this->mine($request, $id)->hasMany(AiArCapture::class, 'experience_id')->find($captureId) ?? throw new AiArException('not_found', 404);
        $captures->delete($capture);

        return ApiResponse::success(null);
    }

    /** AR-021: rough objects and sizes from one photo. An estimate with a notice, never a measurement; the photo is not kept. */
    public function vision(Request $request, AiArVisionService $vision)
    {
        $data = $request->validate(['photo' => ['required', 'file'], 'hint' => ['nullable', 'string', 'max:200']]);

        if (! AiArSetting::current()->is_enabled) {
            throw new AiArException('disabled', 403);
        }

        return ApiResponse::success($vision->analyze($request->file('photo'), $data['hint'] ?? null));
    }

    /** Until the platform has a decor-provider directory: find an active provider by name. Id and name only, no contact data. */
    public function searchProviders(Request $request)
    {
        $q = trim((string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']])['q']);

        return ApiResponse::success(\Modules\Provider\Models\Provider::query()->where('status', \App\Enums\UserStatus::Active)
            ->where('name', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%')->orderBy('name')->limit(10)->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->all());
    }

    // ---- Review loop (customer side) ----

    public function submitForReview(Request $request, int $id, AiArApprovalService $approvals)
    {
        $data = $request->validate(['provider_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $approval = $approvals->submit($request->user(), $this->mine($request, $id), (int) $data['provider_id'], $data['note'] ?? null);

        return ApiResponse::success($this->presentApproval($approval), 'OK', 201);
    }

    public function withdrawReview(Request $request, int $id, int $approvalId, AiArApprovalService $approvals)
    {
        $approval = $this->mine($request, $id)->hasMany(AiArApproval::class, 'experience_id')->find($approvalId) ?? throw new AiArException('not_found', 404);

        return ApiResponse::success($this->presentApproval($approvals->withdraw($approval)));
    }

    public function acceptDesign(Request $request, int $id, AiArApprovalService $approvals)
    {
        return ApiResponse::success($this->presentExperience($approvals->accept($this->mine($request, $id))));
    }

    public function reviews(Request $request, int $id)
    {
        return ApiResponse::success($this->mine($request, $id)->hasMany(AiArApproval::class, 'experience_id')->latest('id')->get()->map(fn ($a) => $this->presentApproval($a))->all());
    }

    // ---- Review loop (provider side) ----

    public function inbox(Request $request)
    {
        $rows = AiArApproval::query()->with('experience')->where('provider_id', $request->user()->getAuthIdentifier())
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->limit(100)->get();

        return ApiResponse::success($rows->map(fn ($a) => $this->presentApproval($a) + ['title' => $a->experience?->title])->all());
    }

    /** The exact version the customer sent, with a short-lived signed link per model so the provider can open it. */
    public function inboxShow(Request $request, int $id)
    {
        $approval = $this->forProvider($request, $id);
        $items = $approval->version->items()->get();
        $names = AiArAsset::query()->whereIn('id', $items->pluck('asset_id'))->get()->keyBy('id');

        return ApiResponse::success($this->presentApproval($approval) + ['title' => $approval->experience?->title, 'scene' => $items->map(fn ($i) => $i->only(['asset_id', 'asset_version_id', 'transform', 'tint', 'visible', 'anchor_type', 'label', 'info']) + [
            'name' => $names->get($i->asset_id)?->displayName(),
            'download_url' => URL::temporarySignedRoute('api.ai-ar.asset-file', now()->addMinutes(10), ['id' => $i->asset_id, 'v' => $i->asset_version_id]),
        ])->all()]);
    }

    public function decide(Request $request, int $id, AiArApprovalService $approvals)
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,request_changes,reject'], 'note' => ['nullable', 'string', 'max:500'], 'quote_minor' => ['nullable', 'integer', 'min:0', 'max:100000000000']]);

        return ApiResponse::success($this->presentApproval($approvals->decide($request->user(), $this->forProvider($request, $id), $data['decision'], $data['note'] ?? null, isset($data['quote_minor']) ? (int) $data['quote_minor'] : null)));
    }

    protected function forProvider(Request $request, int $id): AiArApproval
    {
        return AiArApproval::query()->with(['experience', 'version'])->where('provider_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiArException('not_found', 404);
    }

    protected function presentApproval(AiArApproval $a): array
    {
        return ['id' => $a->id, 'experience_id' => $a->experience_id, 'version_id' => $a->experience_version_id, 'provider_id' => $a->provider_id, 'status' => $a->status,
            'customer_note' => $a->customer_note, 'provider_note' => $a->provider_note, 'quote_minor' => $a->quote_minor, 'currency' => $a->currency,
            'decided_at' => $a->decided_at?->toIso8601String(), 'accepted_at' => $a->accepted_at?->toIso8601String()];
    }

    protected function mine(Request $request, int $id): AiArExperience
    {
        return AiArExperience::query()->where('owner_type', $request->user()->getMorphClass())->where('owner_id', $request->user()->getAuthIdentifier())->find($id)
            ?? throw new AiArException('not_found', 404);
    }

    protected function presentAsset(AiArAsset $a): array
    {
        $v = $a->currentVersion;

        return [
            'id' => $a->id, 'name' => $a->displayName(), 'category' => $a->category, 'source' => $a->source, 'license' => $a->license, 'attribution' => $a->attribution,
            'version_id' => $a->current_version_id, 'size_bytes' => $v?->size_bytes, 'triangles' => $v?->triangle_count, 'dimensions' => $v?->real_dimensions, 'checksum' => $v?->checksum,
        ];
    }

    protected function presentExperience(AiArExperience $e): array
    {
        return ['id' => $e->id, 'type' => $e->type, 'title' => $e->title, 'status' => $e->status, 'version_id' => $e->current_version_id, 'notice' => $this->noticeFor($e->type), 'updated_at' => $e->updated_at?->toIso8601String()];
    }

    /** Shown before any AR session of that type: sizes are estimates, and maintenance guidance never replaces real safety rules. */
    protected function noticeFor(string $type): ?string
    {
        return match ($type) {
            'maintenance' => __('ai.ar_notice_maintenance'),
            'decor', 'real_estate', 'commerce' => __('ai.ar_measurement_notice'),
            default => null,
        };
    }

    protected function presentScene(AiArExperience $e): array
    {
        $items = $e->currentVersion?->items ?? collect();

        return $items->map(fn ($i) => $i->only(['id', 'asset_id', 'asset_version_id', 'transform', 'tint', 'visible', 'anchor_type', 'label', 'info']))->all();
    }
}
