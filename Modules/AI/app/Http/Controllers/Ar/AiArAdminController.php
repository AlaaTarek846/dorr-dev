<?php

namespace Modules\AI\Http\Controllers\Ar;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArAsset;
use Modules\AI\Models\Ar\AiArDeviceProfile;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\Ar\AiArAssetService;

/** Ops console API for the AR engine: settings, the model library with licences and versions, and the tested-device list. */
class AiArAdminController extends Controller
{
    public function __construct(protected AiArAssetService $assets) {}

    public function settings()
    {
        return ApiResponse::success(AiArSetting::current());
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'is_enabled' => ['sometimes', 'boolean'], 'max_asset_mb' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_triangles' => ['sometimes', 'integer', 'min:1000', 'max:2000000'], 'capture_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'commission_percent' => ['sometimes', 'numeric', 'min:0', 'max:50'], 'escrow_release_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ]);
        $setting = AiArSetting::current();
        $setting->update($data);

        return ApiResponse::success($setting->refresh());
    }

    public function assets(Request $request)
    {
        return ApiResponse::success(AiArAsset::query()->with('versions')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest('id')->limit(200)->get());
    }

    public function storeAsset(Request $request)
    {
        $data = $request->validate([
            'name_en' => ['required', 'string', 'max:191'], 'name_ar' => ['nullable', 'string', 'max:191'], 'category' => ['required', 'string', 'max:40'],
            'license' => ['nullable', 'in:'.implode(',', AiArAsset::LICENSES)], 'license_note' => ['nullable', 'string', 'max:255'], 'attribution' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file'],
        ]);

        return ApiResponse::success($this->assets->create($data, $request->file('file'))->load('versions'), 'OK', 201);
    }

    public function addVersion(Request $request, int $id)
    {
        $request->validate(['file' => ['required', 'file'], 'notes' => ['nullable', 'string', 'max:255']]);
        $asset = AiArAsset::query()->find($id) ?? throw new AiArException('not_found', 404);
        $this->assets->addVersion($asset, $request->file('file'), $request->input('notes'));

        return ApiResponse::success($asset->load('versions'), 'OK', 201);
    }

    public function updateAsset(Request $request, int $id)
    {
        $data = $request->validate([
            'license' => ['sometimes', 'nullable', 'in:'.implode(',', AiArAsset::LICENSES)], 'license_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'attribution' => ['sometimes', 'nullable', 'string', 'max:255'], 'name_ar' => ['sometimes', 'nullable', 'string', 'max:191'], 'category' => ['sometimes', 'string', 'max:40'],
        ]);
        $asset = AiArAsset::query()->find($id) ?? throw new AiArException('not_found', 404);
        $asset->update($data);

        return ApiResponse::success($asset->refresh());
    }

    public function activate(Request $request, int $id)
    {
        $request->validate(['version_id' => ['required', 'integer']]);
        $asset = AiArAsset::query()->find($id) ?? throw new AiArException('not_found', 404);
        $version = $asset->versions()->find($request->input('version_id')) ?? throw new AiArException('not_found', 404);

        return ApiResponse::success($this->assets->activateVersion($asset, $version)->load('versions'));
    }

    public function publish(Request $request, int $id)
    {
        $request->validate(['is_public' => ['required', 'boolean']]);
        $asset = AiArAsset::query()->find($id) ?? throw new AiArException('not_found', 404);

        return ApiResponse::success($this->assets->setPublic($asset, (bool) $request->input('is_public')));
    }

    public function reject(int $id)
    {
        return ApiResponse::success($this->assets->reject(AiArAsset::query()->find($id) ?? throw new AiArException('not_found', 404)));
    }

    /** Oversight of design reviews between customers and providers. */
    public function reviews(Request $request)
    {
        $rows = \Modules\AI\Models\Ar\AiArApproval::query()->with('experience:id,title,type,status')
            ->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))->latest('id')->limit(200)->get();

        return ApiResponse::success($rows->map(fn ($r) => [
            'id' => $r->id, 'experience_id' => $r->experience_id, 'title' => $r->experience?->title, 'type' => $r->experience?->type, 'experience_status' => $r->experience?->status,
            'provider_id' => $r->provider_id, 'status' => $r->status, 'customer_note' => $r->customer_note, 'provider_note' => $r->provider_note,
            'decided_at' => $r->decided_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
        ])->all());
    }

    public function orders(Request $request)
    {
        $rows = \Modules\AI\Models\Ar\AiArOrder::query()->with('experience:id,title')->when($request->query('status'), fn ($q, $st) => $q->where('status', $st))->latest('id')->limit(200)->get();

        return ApiResponse::success($rows->map(fn ($o) => AiArOrderController::presentOrder($o) + ['title' => $o->experience?->title, 'provider_id' => $o->provider_id, 'customer_id' => $o->customer_id])->all());
    }

    public function resolveOrder(Request $request, int $id, \Modules\AI\Services\Ar\AiArOrderService $orders)
    {
        $data = $request->validate(['resolution' => ['required', 'in:release,refund'], 'note' => ['required', 'string', 'min:3', 'max:500']]);
        $order = \Modules\AI\Models\Ar\AiArOrder::query()->find($id) ?? throw new AiArException('not_found', 404);

        return ApiResponse::success(AiArOrderController::presentOrder($orders->resolve($order, $data['resolution'], $data['note'])));
    }

    public function devices()
    {
        return ApiResponse::success(AiArDeviceProfile::query()->orderBy('manufacturer')->get());
    }

    public function storeDevice(Request $request)
    {
        $data = $request->validate([
            'platform' => ['nullable', 'string', 'max:10'], 'manufacturer' => ['required', 'string', 'max:60'], 'model_pattern' => ['required', 'string', 'max:120'],
            'arcore_supported' => ['required', 'boolean'], 'depth_supported' => ['nullable', 'boolean'], 'tier' => ['required', 'in:full,basic,fallback_only'],
            'notes' => ['nullable', 'string', 'max:255'], 'verified_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success(AiArDeviceProfile::query()->create($data), 'OK', 201);
    }

    public function destroyDevice(int $id)
    {
        AiArDeviceProfile::query()->whereKey($id)->delete();

        return ApiResponse::success(null);
    }
}
