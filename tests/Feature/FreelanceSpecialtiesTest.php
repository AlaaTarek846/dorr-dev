<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Database\Seeders\FreelanceSpecialtySeeder;
use Modules\Freelance\Models\FreelanceSpecialty;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** FL-101: the specialty catalog — a specialty and its sub-specialties (two levels), names in every language. */
class FreelanceSpecialtiesTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** @return array<string, mixed> */
    private function body(string $en, string $ar, ?int $parentId = null): array
    {
        return ['parent_id' => $parentId, 'translations' => [['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]];
    }

    public function test_an_admin_builds_the_tree_two_levels_with_names_in_both_languages(): void
    {
        $this->asAdmin(['freelance-catalogs.view', 'freelance-catalogs.create', 'freelance-catalogs.update', 'freelance-catalogs.delete']);

        $design = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Design', 'تصميم'))->assertCreated()->assertJsonPath('data.name', 'Design')->json('data');
        $logo = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Logo', 'شعارات', $design['id']))->assertCreated()->assertJsonPath('data.parent', 'Design')->json('data');
        // No sort_order sent: a new one goes to the end of its level.
        $second = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Icons', 'أيقونات', $design['id']))->assertCreated()->json('data');
        $this->assertGreaterThan($logo['sort_order'], $second['sort_order']);

        // A third level is refused.
        $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Deep', 'عميق', $logo['id']))->assertStatus(422)->assertJsonPath('error_code', 'freelance_specialty_two_levels');
        // A specialty with sub-specialties can't itself go under another one.
        $other = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Writing', 'كتابة'))->json('data');
        $this->putJson("/api/admin/v1/freelance-specialties/{$design['id']}", ['parent_id' => $other['id']])->assertStatus(422);

        // The list shows each parent with its children right under it, and both languages are kept.
        $names = collect($this->getJson('/api/admin/v1/freelance-specialties')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertSame(['Design', 'Logo', 'Icons', 'Writing'], $names);
        $this->assertCount(2, $this->getJson("/api/admin/v1/freelance-specialties/{$logo['id']}")->json('data.translations'));
        $this->getJson('/api/admin/v1/freelance-specialties', ['Accept-Language' => 'ar']);

        // Editing a name, and the switch.
        $this->putJson("/api/admin/v1/freelance-specialties/{$logo['id']}", ['translations' => [['locale' => 'en', 'name' => 'Logos']]])->assertOk()->assertJsonPath('data.name', 'Logos');
        $this->patchJson("/api/admin/v1/freelance-specialties/{$logo['id']}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->assertCount(1, $this->getJson('/api/admin/v1/freelance-specialties?status=0')->json('data'));
    }

    public function test_a_specialty_with_sub_specialties_cannot_be_deleted_and_many_can_be_deleted_at_once(): void
    {
        $this->asAdmin(['freelance-catalogs.view', 'freelance-catalogs.create', 'freelance-catalogs.delete', 'freelance-catalogs.multiple-delete', 'freelance-catalogs.update']);

        $parent = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Tech', 'تقنية'))->json('data');
        $child = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Web', 'ويب', $parent['id']))->json('data');
        $lone = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('Lone', 'وحيد'))->json('data');

        $this->deleteJson("/api/admin/v1/freelance-specialties/{$parent['id']}")->assertStatus(422)->assertJsonPath('error_code', 'freelance_specialty_has_children');

        // The parent is skipped while its child stays; the lone one goes.
        $this->postJson('/api/admin/v1/freelance-specialties/delete-multiple', ['ids' => [$parent['id'], $lone['id']]])
            ->assertOk()->assertJsonPath('data.deleted', 1)->assertJsonPath('data.skipped', 1);
        // Picked together with its children, both go (children first).
        $this->postJson('/api/admin/v1/freelance-specialties/delete-multiple', ['ids' => [$parent['id'], $child['id']]])
            ->assertOk()->assertJsonPath('data.deleted', 2);
        $this->assertSame(0, FreelanceSpecialty::query()->count());
    }

    public function test_the_order_is_saved_per_level_and_the_permissions_are_enforced(): void
    {
        $this->asAdmin(['freelance-catalogs.view']);
        $this->postJson('/api/admin/v1/freelance-specialties', $this->body('A', 'أ'))->assertForbidden();

        $this->asAdmin(['freelance-catalogs.view', 'freelance-catalogs.create', 'freelance-catalogs.update']);
        $a = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('A', 'أ'))->json('data');
        $b = $this->postJson('/api/admin/v1/freelance-specialties', $this->body('B', 'ب'))->json('data');
        $this->putJson('/api/admin/v1/freelance-specialties/reorder', ['ordered_ids' => [$b['id'], $a['id']]])->assertOk();

        $names = collect($this->getJson('/api/admin/v1/freelance-specialties')->json('data'))->pluck('name')->all();
        $this->assertSame(['B', 'A'], $names);
    }

    public function test_the_starter_catalog_is_seeded_once_and_feeds_the_policies_screen(): void
    {
        $this->seed(FreelanceSpecialtySeeder::class);
        $count = FreelanceSpecialty::query()->count();
        $this->assertGreaterThan(20, $count);
        $this->seed(FreelanceSpecialtySeeder::class);
        $this->assertSame($count, FreelanceSpecialty::query()->count());

        $this->asAdmin(['freelance-policies.view']);
        $options = $this->getJson('/api/admin/v1/freelance-policies/options')->assertOk()->json('data.specialties');
        $this->assertContains('Programming & Tech › Web development', collect($options)->pluck('name')->all());
    }
}
