<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Database\Seeders\FreelanceSkillSeeder;
use Modules\Freelance\Database\Seeders\FreelanceSpecialtySeeder;
use Modules\Freelance\Events\FreelanceSkillsMerged;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** FL-102: the skill catalog — duplicates caught, a freelancer's suggestions reviewed, merging, and what the apps read. */
class FreelanceSkillsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private FreelanceSpecialty $web;

    private FreelanceSpecialty $design;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
        ]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();

        $this->web = $this->specialty('Web development', 'تطوير المواقع');
        $this->design = $this->specialty('Logo & branding', 'الشعارات والهوية');
    }

    private function specialty(string $en, string $ar, ?int $parent = null): FreelanceSpecialty
    {
        $s = FreelanceSpecialty::query()->create(['parent_id' => $parent, 'status' => true, 'sort_order' => 1]);
        $s->translations()->createMany([['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]]);

        return $s;
    }

    private function asAdmin(array $permissions = ['freelance-catalogs.view', 'freelance-catalogs.create', 'freelance-catalogs.update', 'freelance-catalogs.delete', 'freelance-catalogs.multiple-delete']): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    private function freelancer(string $phone = '+966500000077'): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified', 'locale' => 'en']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    /** @return array<string, mixed> */
    private function body(string $en, string $ar, array $specialtyIds = []): array
    {
        return ['translations' => [['locale' => 'en', 'name' => $en], ['locale' => 'ar', 'name' => $ar]], 'specialty_ids' => $specialtyIds];
    }

    private function skill(string $en, string $ar, string $status = 'active', array $specialties = []): FreelanceSkill
    {
        $s = app(FreelanceSkills::class)->create($this->body($en, $ar, array_map(fn ($sp) => $sp->id, $specialties)));
        if ($status !== 'active') {
            $s->update(['status' => $status]);
        }

        return $s->fresh();
    }

    // -------------------------------------------------------------- names

    public function test_a_name_is_cleaned_before_it_is_compared(): void
    {
        $n = FreelanceSkills::normalize(...);

        $this->assertSame('node.js', $n('  Node.JS  '));
        $this->assertSame('google ads', $n("Google   Ads."));
        $this->assertSame('ادارة المشاريع', $n('إدارة  المشاريع'));
        $this->assertSame('ترجمه', $n('تَرْجَمَـه'));
        $this->assertSame($n('PHP'), $n('php'));
    }

    // -------------------------------------------------------------- admin

    public function test_the_admin_creates_edits_and_switches_a_skill_and_duplicates_are_refused(): void
    {
        $this->asAdmin();

        $skill = $this->postJson('/api/admin/v1/freelance-skills', $this->body('Laravel', 'لارافيل', [$this->web->id]))
            ->assertCreated()->assertJsonPath('data.name', 'Laravel')->assertJsonPath('data.slug', 'laravel')->assertJsonPath('data.status', 'active')->assertJsonPath('data.specialties.0.name', 'Web development')->json('data');
        $this->postJson('/api/admin/v1/freelance-skills', $this->body('  laravel ', 'شيء آخر'))->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_duplicate')->assertJsonPath('data.skill_id', $skill['id']);
        $this->postJson('/api/admin/v1/freelance-skills', ['translations' => [['locale' => 'en', 'name' => 'x']]])->assertUnprocessable();

        $this->putJson("/api/admin/v1/freelance-skills/{$skill['id']}", ['translations' => [['locale' => 'en', 'name' => 'Laravel framework']], 'specialty_ids' => [$this->web->id, $this->design->id]])
            ->assertOk()->assertJsonPath('data.name', 'Laravel framework')->assertJsonCount(2, 'data.specialties');
        $this->patchJson("/api/admin/v1/freelance-skills/{$skill['id']}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->patchJson("/api/admin/v1/freelance-skills/{$skill['id']}/status", ['status' => true])->assertOk()->assertJsonPath('data.status', 'active');

        $this->getJson('/api/admin/v1/freelance-skills?status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.counts.active', 1)->assertJsonPath('pagination.counts.pending', 0);
        $this->getJson('/api/admin/v1/freelance-skills?search=FRAMEWORK')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/freelance-skills?specialty_id='.$this->design->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/v1/freelance-skills/options')->assertOk()->assertJsonCount(2, 'data.specialties');
    }

    public function test_the_permissions_are_enforced(): void
    {
        $this->asAdmin(['freelance-catalogs.view']);
        $skill = $this->skill('PHP', 'بي إتش بي');

        $this->getJson('/api/admin/v1/freelance-skills')->assertOk();
        $this->postJson('/api/admin/v1/freelance-skills', $this->body('A1', 'أ'))->assertForbidden();
        $this->postJson("/api/admin/v1/freelance-skills/{$skill->id}/approve")->assertForbidden();
        $this->postJson("/api/admin/v1/freelance-skills/{$skill->id}/merge", ['target_id' => 1])->assertForbidden();
        $this->deleteJson("/api/admin/v1/freelance-skills/{$skill->id}")->assertForbidden();
    }

    public function test_a_skill_in_use_is_not_deleted_and_bulk_delete_says_how_many(): void
    {
        $this->asAdmin();
        $used = $this->skill('Used', 'مستخدمة');
        $used->update(['usage_count' => 3]);
        $free = $this->skill('Free', 'حرة');
        $other = $this->skill('Other', 'أخرى');

        $this->deleteJson("/api/admin/v1/freelance-skills/{$used->id}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_skill_in_use');
        $this->postJson('/api/admin/v1/freelance-skills/delete-multiple', ['ids' => [$used->id, $free->id, $other->id]])->assertOk()->assertJsonPath('data.deleted', 2)->assertJsonPath('data.skipped', 1);
        $this->assertSame(1, FreelanceSkill::query()->count());
    }

    // -------------------------------------------------------------- suggestions

    public function test_a_freelancer_suggests_a_new_skill_which_waits_and_shows_only_to_them(): void
    {
        $this->skill('Laravel', 'لارافيل', 'active', [$this->web]);
        $mine = $this->freelancer();

        $suggested = $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Tailwind CSS', 'specialty_id' => $this->web->id])
            ->assertCreated()->assertJsonPath('data.created', true)->assertJsonPath('data.pending', true)->json('data');
        $this->assertSame('pending', FreelanceSkill::query()->find($suggested['id'])->status);

        // They see it in their list; the catalog for everyone else doesn't.
        $names = collect($this->getJson('/api/freelance/v1/skills')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertContains('Tailwind CSS', $names);
        $this->freelancer('+966500000078');
        $this->assertNotContains('Tailwind CSS', collect($this->getJson('/api/freelance/v1/skills')->json('data'))->pluck('name')->all());
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');
        $this->assertNotContains('Tailwind CSS', collect($this->getJson('/api/mobile/v1/freelance/skills')->assertOk()->json('data'))->pluck('name')->all());
        $this->assertSame('pending', FreelanceSkill::query()->find($suggested['id'])->status);
        $this->assertSame($mine->id, FreelanceSkill::query()->find($suggested['id'])->suggested_by_id);
    }

    public function test_suggesting_an_existing_skill_in_any_form_returns_it_instead_of_a_copy(): void
    {
        $laravel = $this->skill('Laravel', 'لارافيل');
        $this->freelancer();

        foreach (['laravel', '  LARAVEL.', 'لارافيل'] as $typed) {
            $this->postJson('/api/freelance/v1/skills/suggest', ['name' => $typed, 'locale' => $typed === 'لارافيل' ? 'ar' : 'en'])
                ->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.id', $laravel->id);
        }
        $this->assertSame(1, FreelanceSkill::query()->count());

        // A switched-off one can't be suggested again.
        $laravel->update(['status' => 'inactive']);
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Laravel'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_unavailable');
    }

    public function test_the_daily_limit_of_suggestions_holds(): void
    {
        config(['freelance.skills.suggest_daily_limit' => 2]);
        $this->freelancer();

        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Alpha skill'])->assertCreated();
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Beta skill'])->assertCreated();
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Gamma skill'])->assertStatus(429)->assertJsonPath('error_code', 'freelance_skill_suggest_limit');
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'alpha SKILL'])->assertOk()->assertJsonPath('data.created', false); // an existing one is never limited
    }

    public function test_the_admin_approves_with_both_languages_and_the_freelancer_is_told(): void
    {
        $f = $this->freelancer();
        $id = $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Tailwind CSS', 'locale' => 'en'])->json('data.id');

        $this->asAdmin();
        // One language is not enough to go live.
        $this->postJson("/api/admin/v1/freelance-skills/{$id}/approve")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_translations_missing')->assertJsonPath('data.locales', ['ar']);
        $this->assertSame('pending', FreelanceSkill::query()->find($id)->status);

        $this->postJson("/api/admin/v1/freelance-skills/{$id}/approve", ['translations' => [['locale' => 'ar', 'name' => 'تيلويند']], 'specialty_ids' => [$this->web->id]])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.specialties.0.name', 'Web development');
        $this->assertSame('freelance.skill.approved', $f->notifications()->first()->data['event']);
        $this->postJson("/api/admin/v1/freelance-skills/{$id}/approve")->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_invalid_state');
    }

    public function test_a_rejected_suggestion_tells_the_freelancer_why_and_is_not_suggested_again(): void
    {
        $f = $this->freelancer();
        $id = $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Spammy thing'])->json('data.id');

        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-skills/{$id}/reject", ['reason' => 'Not a skill'])->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Not a skill');
        $note = $f->notifications()->first()->data;
        $this->assertSame('freelance.skill.rejected', $note['event']);
        $this->assertSame(['name' => 'Spammy thing', 'reason' => 'Not a skill'], array_intersect_key($note['variables'], ['name' => 1, 'reason' => 1]));

        $this->freelancer('+966500000079');
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'spammy thing'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_unavailable');
    }

    // -------------------------------------------------------------- merge

    public function test_merging_folds_a_duplicate_into_a_skill_and_keeps_its_name_as_an_alias(): void
    {
        Event::fake([FreelanceSkillsMerged::class]);
        $keep = $this->skill('JavaScript', 'جافاسكريبت', 'active', [$this->web]);
        $dup = $this->skill('JS', 'جي إس', 'active', [$this->design]);
        $dup->update(['usage_count' => 4]);
        $keep->update(['usage_count' => 6]);
        $alias = $this->skill('Java Script', 'جافا سكريبت');
        $alias->update(['status' => 'merged', 'merged_into_id' => $dup->id]);

        $this->asAdmin();
        $this->postJson("/api/admin/v1/freelance-skills/{$dup->id}/merge", ['target_id' => $keep->id])->assertOk()
            ->assertJsonPath('data.id', $keep->id)->assertJsonCount(2, 'data.specialties');

        $this->assertSame('merged', $dup->fresh()->status);
        $this->assertSame($keep->id, $dup->fresh()->merged_into_id);
        $this->assertSame(10, $keep->fresh()->usage_count);
        $this->assertSame(0, $dup->fresh()->usage_count);
        $this->assertSame($keep->id, $alias->fresh()->merged_into_id, 'what was already merged into the duplicate follows it');
        Event::assertDispatched(FreelanceSkillsMerged::class, fn ($e) => $e->sourceId === $dup->id && $e->targetId === $keep->id);

        // The alias name still resolves to the survivor, in the catalog and in a suggestion.
        $names = collect($this->getJson('/api/admin/v1/freelance-skills?status=active')->json('data'))->pluck('name')->all();
        $this->assertSame(['JavaScript'], $names);
        $this->freelancer();
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'js'])->assertOk()->assertJsonPath('data.id', $keep->id)->assertJsonPath('data.created', false);
        // A merged skill can't be deleted away from under its target.
        $this->asAdmin(['freelance-catalogs.delete']);
        $this->deleteJson("/api/admin/v1/freelance-skills/{$keep->id}")->assertStatus(409);
    }

    public function test_a_bad_merge_is_refused(): void
    {
        $a = $this->skill('Alpha', 'ألفا');
        $b = $this->skill('Beta', 'بيتا');
        $pending = $this->skill('Gamma', 'جاما', 'pending');
        $this->asAdmin();

        $this->postJson("/api/admin/v1/freelance-skills/{$a->id}/merge", ['target_id' => $a->id])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_skill_merge_invalid');
        $this->postJson("/api/admin/v1/freelance-skills/{$a->id}/merge", ['target_id' => $pending->id])->assertUnprocessable();
        $this->postJson("/api/admin/v1/freelance-skills/{$a->id}/merge", ['target_id' => $b->id])->assertOk();
        $this->postJson("/api/admin/v1/freelance-skills/{$a->id}/merge", ['target_id' => $b->id])->assertUnprocessable(); // already merged
        $this->postJson("/api/admin/v1/freelance-skills/{$b->id}/merge", ['target_id' => $a->id])->assertUnprocessable(); // into a merged one
    }

    // -------------------------------------------------------------- the apps

    public function test_the_apps_list_by_specialty_search_and_language(): void
    {
        $this->skill('Laravel', 'لارافيل', 'active', [$this->web]);
        $this->skill('Logo design', 'تصميم الشعارات', 'active', [$this->design]);
        $this->skill('Switched off', 'موقوفة', 'inactive', [$this->web]);
        $this->freelancer();

        $this->assertEqualsCanonicalizing(['Laravel', 'Logo design'], collect($this->getJson('/api/freelance/v1/skills')->assertOk()->json('data'))->pluck('name')->all());
        $this->assertSame(['Laravel'], collect($this->getJson('/api/freelance/v1/skills?specialty_id='.$this->web->id)->json('data'))->pluck('name')->all());
        $this->assertSame(['Logo design'], collect($this->getJson('/api/freelance/v1/skills?q=LOGO')->json('data'))->pluck('name')->all());
        $this->assertSame(['تصميم الشعارات'], collect($this->getJson('/api/freelance/v1/skills?q=شعارات', ['Accept-Language' => 'ar'])->json('data'))->pluck('name')->all());
        $this->getJson('/api/freelance/v1/skills?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 2);
    }

    public function test_a_main_specialty_lists_the_skills_of_its_sub_specialties_too(): void
    {
        $api = $this->specialty('Software & APIs', 'البرمجيات', $this->web->id);
        $this->skill('REST APIs', 'واجهات REST', 'active', [$api]);
        $this->skill('Laravel', 'لارافيل', 'active', [$this->web]);
        $this->freelancer();

        $this->assertEqualsCanonicalizing(['REST APIs', 'Laravel'], collect($this->getJson('/api/freelance/v1/skills?specialty_id='.$this->web->id)->json('data'))->pluck('name')->all());
        $this->assertSame(['REST APIs'], collect($this->getJson('/api/freelance/v1/skills?specialty_id='.$api->id)->json('data'))->pluck('name')->all());
    }

    public function test_the_apps_need_an_account_and_the_service_open_in_the_country(): void
    {
        $this->getJson('/api/freelance/v1/skills')->assertUnauthorized();
        $this->postJson('/api/freelance/v1/skills/suggest', ['name' => 'Anything'])->assertUnauthorized();
        $this->getJson('/api/mobile/v1/freelance/skills')->assertUnauthorized();

        FreelanceFeatureRule::query()->where('feature', 'freelance')->update(['enabled' => false]);
        FreelanceFeatures::forget();
        $this->freelancer();
        $this->getJson('/api/freelance/v1/skills')->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        // A client can read but has no route to suggest.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]), [], 'user_api');
        $this->postJson('/api/mobile/v1/freelance/skills/suggest', ['name' => 'Anything'])->assertStatus(404);
    }

    // -------------------------------------------------------------- the starter catalog

    public function test_the_starter_catalog_is_seeded_once_and_leaves_an_admins_changes_alone(): void
    {
        $this->seed(FreelanceSpecialtySeeder::class);
        $this->seed(FreelanceSkillSeeder::class);
        $count = FreelanceSkill::query()->count();
        $this->assertGreaterThan(30, $count);
        $laravel = FreelanceSkill::query()->where('slug', 'laravel')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Web development', 'Software & APIs'], $laravel->specialties()->with('translations')->get()->map(fn ($s) => $s->translations->firstWhere('locale', 'en')->name)->all());

        $laravel->update(['status' => 'inactive']);
        $laravel->specialties()->detach();
        $this->seed(FreelanceSkillSeeder::class);

        $this->assertSame($count, FreelanceSkill::query()->count());
        $this->assertSame('inactive', $laravel->fresh()->status, 'a switched-off skill stays off');
    }
}
