<?php

namespace Modules\AI\Services\Ar;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArAsset;
use Modules\AI\Models\Ar\AiArAssetVersion;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\AiAuditTrail;

/**
 * 3D models: vetting on upload (real GLB, size, triangle limit, no external references), versioning (a new
 * upload never replaces what experiences already use until an admin activates it) and the licence gate.
 */
class AiArAssetService
{
    public function __construct(protected GlbInspector $inspector, protected AiAuditTrail $audit) {}

    /** Creates a new asset with its first version. Admin/library uploads are public candidates; others are private to the owner. */
    public function create(array $meta, UploadedFile $file, ?Authenticatable $owner = null, ?string $notes = null): AiArAsset
    {
        $asset = AiArAsset::query()->create([
            'slug' => Str::slug($meta['name_en']).'-'.Str::lower(Str::random(5)),
            'name_en' => $meta['name_en'],
            'name_ar' => $meta['name_ar'] ?? null,
            'category' => $meta['category'],
            'source' => $owner === null ? 'library' : 'uploaded',
            'license' => $meta['license'] ?? ($owner === null ? null : 'own'),
            'license_note' => $meta['license_note'] ?? null,
            'attribution' => $meta['attribution'] ?? null,
            'owner_type' => $owner?->getMorphClass(),
            'owner_id' => $owner?->getAuthIdentifier(),
            'status' => AiArAsset::DRAFT,
            'is_public' => false,
        ]);

        try {
            $version = $this->addVersion($asset, $file, $notes);
        } catch (AiArException $e) {
            $asset->delete();

            throw $e;
        }

        // A customer's own upload is usable by them straight away; library models wait for an admin to check the licence.
        if ($owner !== null) {
            $asset->forceFill(['status' => AiArAsset::ACTIVE, 'current_version_id' => $version->id])->save();
        }

        $this->audit->record('ar_asset_uploaded', $owner, 'info', 'ai_ar_asset', $asset->id, null, ['triangles' => $version->triangle_count]);

        return $asset->refresh();
    }

    /** Adds a version; the asset keeps pointing at its current one until activateVersion(). */
    public function addVersion(AiArAsset $asset, UploadedFile $file, ?string $notes = null): AiArAssetVersion
    {
        $settings = AiArSetting::current();
        $bytes = (string) file_get_contents($file->getRealPath());

        if (strlen($bytes) > $settings->max_asset_mb * 1024 * 1024) {
            throw new AiArException('asset_too_large', 422);
        }

        $info = $this->inspector->inspect($bytes);

        if ($info['triangles'] > $settings->max_triangles) {
            throw new AiArException('asset_too_complex', 422);
        }

        $number = ((int) $asset->versions()->max('version_no')) + 1;
        $path = 'ai-ar/assets/'.$asset->id.'/v'.$number.'.glb';
        Storage::disk('local')->put($path, $bytes);

        return $asset->versions()->create([
            'version_no' => $number,
            'glb_path' => $path,
            'size_bytes' => strlen($bytes),
            'triangle_count' => $info['triangles'],
            'real_dimensions' => $info['dimensions'],
            'optimized' => false,
            'checksum' => hash('sha256', $bytes),
            'notes' => $notes,
        ]);
    }

    /** Makes a version the one experiences should load. Library assets need a licence first. */
    public function activateVersion(AiArAsset $asset, AiArAssetVersion $version): AiArAsset
    {
        if ($version->asset_id !== $asset->id) {
            throw new AiArException('not_found', 404);
        }

        if ($asset->source === 'library' && blank($asset->license)) {
            throw new AiArException('asset_license_required', 422);
        }

        $asset->forceFill(['current_version_id' => $version->id, 'status' => AiArAsset::ACTIVE])->save();

        return $asset->refresh();
    }

    public function setPublic(AiArAsset $asset, bool $public): AiArAsset
    {
        if ($public && ($asset->status !== AiArAsset::ACTIVE || blank($asset->license))) {
            throw new AiArException('asset_license_required', 422);
        }

        $asset->forceFill(['is_public' => $public])->save();

        return $asset->refresh();
    }

    public function reject(AiArAsset $asset): AiArAsset
    {
        $asset->forceFill(['status' => AiArAsset::REJECTED, 'is_public' => false])->save();

        return $asset->refresh();
    }

    /** What a caller may see: active public models plus their own uploads. */
    public function visibleTo(Authenticatable $viewer)
    {
        return AiArAsset::query()->where('status', AiArAsset::ACTIVE)->whereNotNull('current_version_id')
            ->where(fn ($q) => $q->where('is_public', true)->orWhere(fn ($own) => $own->where('owner_type', $viewer->getMorphClass())->where('owner_id', $viewer->getAuthIdentifier())));
    }

    public function canView(Authenticatable $viewer, AiArAsset $asset): bool
    {
        return $asset->status === AiArAsset::ACTIVE && $asset->current_version_id !== null
            && ($asset->is_public || ($asset->owner_type === $viewer->getMorphClass() && (int) $asset->owner_id === (int) $viewer->getAuthIdentifier()));
    }
}
