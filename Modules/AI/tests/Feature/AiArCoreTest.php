<?php

namespace Modules\AI\Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\AI\Exceptions\AiArException;
use Modules\AI\Models\Ar\AiArAsset;
use Modules\AI\Models\Ar\AiArDeviceProfile;
use Modules\AI\Models\Ar\AiArSetting;
use Modules\AI\Services\Ar\AiArAssetService;
use Modules\AI\Services\Ar\AiArCapabilityService;
use Modules\AI\Services\Ar\AiArExperienceService;
use Modules\AI\Services\Ar\GlbInspector;
use Modules\User\Models\User;
use Tests\TestCase;

/** DORR AR Core Engine, Phase 1: GLB vetting, versioned assets and scenes, capability tiers, API isolation. */
class AiArCoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $currency = Currency::create(['code' => 'EGP', 'symbol' => 'ج.م', 'decimal_places' => 2]);
        Country::create([
            'code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'eg'])->id, 'currency_id' => $currency->id,
        ]);
    }

    /** A minimal but structurally valid GLB: JSON chunk only, one mesh with $triangles triangles and the given bounds. */
    private function glb(int $triangles = 12, array $min = [-0.5, 0, -0.25], array $max = [0.5, 1.0, 0.25], array $extra = []): string
    {
        $json = json_encode($extra + [
            'asset' => ['version' => '2.0'],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1]]]],
            'accessors' => [['count' => 8, 'min' => $min, 'max' => $max], ['count' => $triangles * 3]],
        ]);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

        return pack('V3', 0x46546C67, 2, 20 + strlen($json)).pack('V2', strlen($json), 0x4E4F534A).$json;
    }

    private function file(string $bytes, string $name = 'chair.glb'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function user(): User
    {
        $user = User::query()->create(['name' => 'A', 'email' => 'a-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);
        Sanctum::actingAs($user, ['*'], 'user_api');

        return $user;
    }

    public function test_inspector_reads_triangles_and_real_dimensions(): void
    {
        $info = (new GlbInspector)->inspect($this->glb(12));

        $this->assertSame(12, $info['triangles']);
        $this->assertSame(['width' => 1.0, 'height' => 1.0, 'depth' => 0.5], $info['dimensions']);
    }

    public function test_inspector_refuses_non_glb_and_external_references(): void
    {
        foreach (['not a model at all, just text content here', substr($this->glb(), 0, 30), $this->glb(12, extra: ['buffers' => [['uri' => 'http://evil.example/x.bin', 'byteLength' => 1]]])] as $bad) {
            try {
                (new GlbInspector)->inspect($bad);
                $this->fail('accepted');
            } catch (AiArException $e) {
                $this->assertContains($e->reason, ['asset_not_glb', 'asset_external_refs']);
            }
        }

        $this->assertSame(12, (new GlbInspector)->inspect($this->glb(12, extra: ['buffers' => [['uri' => 'data:application/octet-stream;base64,AAAA', 'byteLength' => 3]]]))['triangles']);
    }

    public function test_upload_limits_are_enforced_and_nothing_is_kept_on_refusal(): void
    {
        AiArSetting::current()->update(['max_triangles' => 1000]);
        $svc = app(AiArAssetService::class);

        try {
            $svc->create(['name_en' => 'Huge', 'category' => 'furniture'], $this->file($this->glb(5000)), $this->user());
            $this->fail('complex');
        } catch (AiArException $e) {
            $this->assertSame('asset_too_complex', $e->reason);
        }

        $this->assertSame(0, AiArAsset::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_new_version_does_not_change_what_old_experiences_use(): void
    {
        $user = $this->user();
        $assets = app(AiArAssetService::class);
        $asset = $assets->create(['name_en' => 'Chair', 'category' => 'furniture'], $this->file($this->glb(12)), $user);
        $v1 = $asset->current_version_id;

        $experiences = app(AiArExperienceService::class);
        $exp = $experiences->create($user, null, 'decor', 'Living room', 'decor-test');
        $transform = ['position' => [0, 0, -1.5], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]];
        $experiences->saveScene($user, $exp, [['asset_id' => $asset->id, 'transform' => $transform, 'tint' => '#112233']]);

        $v2 = $assets->addVersion($asset, $this->file($this->glb(20)));
        $this->assertSame($v1, $asset->refresh()->current_version_id, 'a new upload must not become current by itself');

        $assets->activateVersion($asset, $v2);
        $this->assertSame($v2->id, $asset->refresh()->current_version_id);
        $this->assertSame($v1, $exp->refresh()->currentVersion->items->first()->asset_version_id, 'the saved scene keeps the version it was placed with');

        $experiences->saveScene($user, $exp, [['asset_id' => $asset->id, 'transform' => $transform]], 'second save');
        $this->assertSame(2, $exp->versions()->count());
        $this->assertSame($v2->id, $exp->refresh()->currentVersion->items->first()->asset_version_id);
    }

    public function test_library_models_need_a_licence_and_private_uploads_stay_private(): void
    {
        $assets = app(AiArAssetService::class);
        $library = $assets->create(['name_en' => 'Lamp', 'category' => 'furniture'], $this->file($this->glb()));

        try {
            $assets->activateVersion($library, $library->versions()->first());
            $this->fail('licence');
        } catch (AiArException $e) {
            $this->assertSame('asset_license_required', $e->reason);
        }

        $library->update(['license' => 'cc0']);
        $assets->activateVersion($library->refresh(), $library->versions()->first());
        $assets->setPublic($library->refresh(), true);

        $owner = $this->user();
        $mine = $assets->create(['name_en' => 'Mine', 'category' => 'furniture'], $this->file($this->glb()), $owner);
        $other = User::query()->create(['name' => 'B', 'email' => 'b-'.uniqid().'@example.test', 'password' => bcrypt('x-not-real'), 'status' => UserStatus::Active]);

        $this->assertTrue($assets->canView($other, $library));
        $this->assertFalse($assets->canView($other, $mine));
        $this->assertTrue($assets->canView($owner, $mine));
        $this->assertEqualsCanonicalizing([$library->id, $mine->id], $assets->visibleTo($owner)->pluck('id')->all());
    }

    public function test_capability_tiers_and_tested_device_can_only_lower(): void
    {
        $svc = app(AiArCapabilityService::class);
        $this->assertSame('full', $svc->evaluate(['arcore_supported' => true, 'depth_supported' => true])['tier']);
        $this->assertSame('basic', $svc->evaluate(['arcore_supported' => true])['tier']);
        $none = $svc->evaluate(['arcore_supported' => false]);
        $this->assertSame(['fallback_only', false, 'viewer_3d'], [$none['tier'], $none['ar_available'], $none['fallback']]);

        AiArDeviceProfile::create(['manufacturer' => 'Acme', 'model_pattern' => 'X1%', 'arcore_supported' => true, 'tier' => 'basic']);
        $lowered = $svc->evaluate(['manufacturer' => 'ACME', 'model' => 'X100', 'arcore_supported' => true, 'depth_supported' => true]);
        $this->assertSame('basic', $lowered['tier']);
        $this->assertTrue($lowered['matched_profile']);

        AiArDeviceProfile::create(['manufacturer' => 'Zed', 'model_pattern' => 'Z%', 'arcore_supported' => true, 'tier' => 'full']);
        $this->assertSame('basic', $svc->evaluate(['manufacturer' => 'Zed', 'model' => 'Z9', 'arcore_supported' => true])['tier'], 'a tested row never raises the tier');
    }

    public function test_api_scene_round_trip_signed_download_and_isolation(): void
    {
        $this->user();
        $up = $this->postJson('/api/user/v1/ai-ar/assets', ['name_en' => 'Table', 'category' => 'furniture', 'file' => $this->file($this->glb(), 'table.glb')])->assertStatus(201);
        $assetId = $up->json('data.id');
        $this->assertEquals(1.0, $up->json("data.dimensions.width"));

        $url = $this->getJson("/api/user/v1/ai-ar/assets/$assetId")->assertOk()->json('data.download_url');
        $this->get($url)->assertOk();
        $this->get(preg_replace("/signature=[a-f0-9]+/", "signature=bad", $url))->assertStatus(403);

        $exp = $this->postJson('/api/user/v1/ai-ar/experiences', ['type' => 'free', 'title' => 'Test'])->assertStatus(201)->json('data.id');
        $scene = ['note' => 'first', 'items' => [['asset_id' => $assetId, 'transform' => ['position' => [0, 0, -1], 'rotation' => [0, 0, 0, 1], 'scale' => [1, 1, 1]]]]];
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", $scene)->assertOk()->assertJsonCount(1, 'data.scene');
        $this->putJson("/api/user/v1/ai-ar/experiences/$exp/scene", ['items' => [['asset_id' => $assetId, 'transform' => ['position' => [0, 0, 0], 'rotation' => [0, 0, 0, 1], 'scale' => [0, 1, 1]]]]])->assertStatus(422);
        $this->getJson("/api/user/v1/ai-ar/launch-check?type=free&asset_ids[]=$assetId")->assertOk()->assertJsonPath('data.show', true)->assertJsonPath('data.mode', 'viewer_3d');
        $this->getJson('/api/user/v1/ai-ar/launch-check?type=free&asset_ids[]=999')->assertJsonPath('data.show', false)->assertJsonPath('data.reason', 'no_content');

        $this->user();
        $this->getJson("/api/user/v1/ai-ar/experiences/$exp")->assertStatus(404);
        $this->getJson("/api/user/v1/ai-ar/assets/$assetId")->assertStatus(404);
    }

    public function test_admin_publishes_a_library_model_only_with_a_licence(): void
    {
        Sanctum::actingAs(Admin::query()->create(['name' => 'A', 'email' => 'ad-'.uniqid().'@example.test', 'password' => 'password', 'status' => true]), ['*'], 'admin_api');

        $id = $this->postJson('/api/admin/v1/ai-ar/assets', ['name_en' => 'Sofa', 'category' => 'furniture', 'file' => $this->file($this->glb(), 'sofa.glb')])->assertStatus(201)->json('data.id');
        $versionId = AiArAsset::find($id)->versions()->first()->id;

        $this->postJson("/api/admin/v1/ai-ar/assets/$id/activate", ['version_id' => $versionId])->assertStatus(422);
        $this->putJson("/api/admin/v1/ai-ar/assets/$id", ['license' => 'cc0'])->assertOk();
        $this->postJson("/api/admin/v1/ai-ar/assets/$id/activate", ['version_id' => $versionId])->assertOk();
        $this->postJson("/api/admin/v1/ai-ar/assets/$id/publish", ['is_public' => true])->assertOk()->assertJsonPath('data.is_public', true);
        $this->postJson('/api/admin/v1/ai-ar/devices', ['manufacturer' => 'Acme', 'model_pattern' => 'X%', 'arcore_supported' => true, 'tier' => 'basic'])->assertStatus(201);
        $this->putJson('/api/admin/v1/ai-ar/settings', ['max_triangles' => 50])->assertStatus(422);
    }
}
