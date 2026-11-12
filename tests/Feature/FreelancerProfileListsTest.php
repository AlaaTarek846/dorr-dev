<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerCertificate;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\Freelance\Services\Catalog\FreelanceSkills;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** FL-104: a freelancer's specialties, skills, work history and certificates — within limits, scoped to them, kept in step with the catalogs. */
class FreelancerProfileListsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    private FreelanceSpecialty $web;

    private FreelanceSpecialty $design;

    private FreelanceSpecialty $writing;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();

        $this->web = $this->specialty('Web development');
        $this->design = $this->specialty('Logo & branding');
        $this->writing = $this->specialty('Writing');
        $this->me = $this->freelancer('+966500000077');
    }

    private function specialty(string $name, bool $active = true): FreelanceSpecialty
    {
        $s = FreelanceSpecialty::query()->create(['status' => $active, 'sort_order' => 1]);
        $s->translations()->createMany([['locale' => 'en', 'name' => $name], ['locale' => 'ar', 'name' => $name]]);

        return $s;
    }

    private function skill(string $name, string $status = 'active'): FreelanceSkill
    {
        $s = FreelanceSkill::query()->create(['slug' => str($name)->slug(), 'status' => $status]);
        $s->translations()->create(['locale' => 'en', 'name' => $name, 'normalized' => FreelanceSkills::normalize($name)]);

        return $s;
    }

    private function freelancer(string $phone): Freelancer
    {
        $f = Freelancer::query()->create(['name' => 'Sara', 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($f, [], 'freelance_api');

        return $f;
    }

    // -------------------------------------------------------------- specialties

    public function test_specialties_are_replaced_with_one_primary_and_listed_primary_first(): void
    {
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->design->id, $this->web->id], 'primary_id' => $this->web->id])->assertOk()
            ->assertJsonPath('data.0.name', 'Web development')->assertJsonPath('data.0.is_primary', true)->assertJsonPath('data.1.is_primary', false);

        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->writing->id], 'primary_id' => $this->writing->id])->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/freelance/v1/profile/specialties')->assertOk()->assertJsonPath('data.0.name', 'Writing');
    }

    public function test_bad_specialties_are_refused(): void
    {
        $off = $this->specialty('Switched off', false);
        $max = (int) config('freelance.profile.max_specialties');
        $many = collect(range(1, $max + 1))->map(fn ($i) => $this->specialty("S{$i}")->id)->all();

        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->web->id], 'primary_id' => $this->design->id])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_primary_invalid');
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$off->id], 'primary_id' => $off->id])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_specialty_invalid')->assertJsonPath('data.ids', [$off->id]);
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [9999], 'primary_id' => 9999])->assertUnprocessable();
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => $many, 'primary_id' => $many[0]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit')->assertJsonPath('data.max', $max);
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [], 'primary_id' => 1])->assertUnprocessable();
    }

    public function test_a_specialty_switched_off_later_stays_on_the_profile_and_one_in_use_cannot_be_deleted(): void
    {
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->web->id, $this->design->id], 'primary_id' => $this->web->id])->assertOk();
        $this->design->update(['status' => false]);

        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->web->id, $this->design->id], 'primary_id' => $this->web->id])->assertOk()
            ->assertJsonPath('data.1.is_active', false);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'A', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        Permission::findOrCreate('freelance-catalogs.delete', 'admin_api');
        $admin->givePermissionTo('freelance-catalogs.delete');
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');
        $this->deleteJson("/api/admin/v1/freelance-specialties/{$this->web->id}")->assertStatus(409)->assertJsonPath('error_code', 'freelance_specialty_in_use');
        $this->deleteJson("/api/admin/v1/freelance-specialties/{$this->writing->id}")->assertOk();
    }

    // -------------------------------------------------------------- skills

    public function test_skills_are_replaced_with_levels_and_their_usage_follows(): void
    {
        $php = $this->skill('PHP');
        $vue = $this->skill('Vue');
        $go = $this->skill('Go');

        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $php->id, 'level' => 'expert'], ['skill_id' => $vue->id]]])->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.level', 'expert');
        $this->assertSame([1, 1, 0], [$php->fresh()->usage_count, $vue->fresh()->usage_count, $go->fresh()->usage_count]);

        // Another freelancer lists PHP too.
        $this->freelancer('+966500000078');
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $php->id], ['skill_id' => $go->id]]])->assertOk();
        $this->assertSame([2, 1, 1], [$php->fresh()->usage_count, $vue->fresh()->usage_count, $go->fresh()->usage_count]);

        // Removing one gives its usage back; clearing the list gives all back.
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $go->id]]])->assertOk();
        $this->assertSame(1, $php->fresh()->usage_count);
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => []])->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $go->fresh()->usage_count);
        // The first freelancer is untouched.
        $this->assertSame(1, $php->fresh()->usage_count);
    }

    public function test_bad_skills_are_refused(): void
    {
        $php = $this->skill('PHP');
        $off = $this->skill('Retired', 'inactive');
        $rejected = $this->skill('Nope', 'rejected');
        $someonesPending = $this->skill('Theirs', 'pending');
        $someonesPending->update(['suggested_by_type' => 'freelancer', 'suggested_by_id' => 999]);

        foreach ([$off->id, $rejected->id, $someonesPending->id, 9999] as $id) {
            $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $id]]])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_skill_invalid');
        }
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $php->id, 'level' => 'god']]])->assertUnprocessable();
        $max = (int) config('freelance.profile.max_skills');
        $rows = collect(range(1, $max + 1))->map(fn ($i) => ['skill_id' => $this->skill("Skill {$i}")->id])->all();
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => $rows])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit');
    }

    public function test_the_same_skill_twice_keeps_the_higher_level_and_a_merged_id_means_its_survivor(): void
    {
        $keep = $this->skill('JavaScript');
        $dup = $this->skill('JS');
        $dup->update(['status' => 'merged', 'merged_into_id' => $keep->id]);

        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $dup->id, 'level' => 'advanced'], ['skill_id' => $keep->id, 'level' => 'beginner']]])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $keep->id)->assertJsonPath('data.0.level', 'advanced');
        $this->assertSame(1, $keep->fresh()->usage_count);
    }

    public function test_a_suggested_skill_can_be_listed_while_it_waits_and_leaves_if_rejected(): void
    {
        $suggested = app(FreelanceSkills::class)->suggest($this->me, 'Tailwind CSS', 'en')['skill'];

        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $suggested->id]]])->assertOk()->assertJsonPath('data.0.pending', true);
        $this->assertSame(1, $this->me->skills()->count());

        app(FreelanceSkills::class)->reject($suggested, 'Not a skill');

        $this->assertSame(0, $this->me->skills()->count(), 'rejected: it leaves the profile');
        $this->assertSame(0, $suggested->fresh()->usage_count);

        $approved = app(FreelanceSkills::class)->suggest($this->me, 'Alpine.js', 'en')['skill'];
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $approved->id]]])->assertOk();
        app(FreelanceSkills::class)->approve($approved, [['locale' => 'ar', 'name' => 'ألباين']]);
        $this->assertSame(1, $this->me->skills()->count(), 'approved: it stays');
        $this->getJson('/api/freelance/v1/profile/skills')->assertJsonPath('data.0.pending', false);
    }

    public function test_merging_skills_moves_what_freelancers_listed_without_duplicates(): void
    {
        $keep = $this->skill('JavaScript');
        $dup = $this->skill('JS');
        $other = $this->freelancer('+966500000078');

        // `me` lists both, the other one only the duplicate.
        $this->me->skills()->attach([$keep->id => ['level' => 'beginner'], $dup->id => ['level' => 'expert']]);
        $other->skills()->attach($dup->id, ['level' => 'advanced']);
        $keep->update(['usage_count' => 1]);
        $dup->update(['usage_count' => 2]);

        app(FreelanceSkills::class)->merge($dup, $keep);

        $this->assertSame(1, $this->me->skills()->count());
        $this->assertSame('expert', $this->me->skills()->first()->pivot->level, 'the higher level survives');
        $this->assertSame($keep->id, $other->skills()->first()->id);
        $this->assertSame(0, \DB::table('freelancer_skills')->where('freelance_skill_id', $dup->id)->count());
        $this->assertSame(2, $keep->fresh()->usage_count, 'two freelancers list it (one had both)');
    }

    // -------------------------------------------------------------- work history

    public function test_work_history_is_added_edited_and_deleted_with_its_dates_checked(): void
    {
        $id = $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Backend developer', 'company' => 'Acme', 'started_on' => '2020-01-01', 'ended_on' => '2022-06-30'])
            ->assertCreated()->assertJsonPath('data.is_current', false)->json('data.id');
        $current = $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Lead', 'started_on' => '2022-07-01', 'ended_on' => '2023-01-01', 'is_current' => true])
            ->assertCreated()->assertJsonPath('data.ended_on', null)->assertJsonPath('data.is_current', true)->json('data.id');

        // The current job is listed first.
        $this->assertSame([$current, $id], collect($this->getJson('/api/freelance/v1/profile/experiences')->json('data'))->pluck('id')->all());

        $this->putJson("/api/freelance/v1/profile/experiences/{$id}", ['company' => 'Acme Inc'])->assertOk()->assertJsonPath('data.company', 'Acme Inc')->assertJsonPath('data.title', 'Backend developer');
        $this->putJson("/api/freelance/v1/profile/experiences/{$id}", ['ended_on' => '2019-01-01'])->assertUnprocessable(); // before it started
        $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Future', 'started_on' => now()->addDay()->toDateString()])->assertUnprocessable();
        $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'x', 'started_on' => '2020-01-01'])->assertUnprocessable();

        $this->deleteJson("/api/freelance/v1/profile/experiences/{$id}")->assertOk();
        $this->getJson('/api/freelance/v1/profile/experiences')->assertJsonCount(1, 'data');
    }

    public function test_the_work_history_limit_holds(): void
    {
        config(['freelance.profile.max_experiences' => 2]);
        foreach ([1, 2] as $i) {
            $this->postJson('/api/freelance/v1/profile/experiences', ['title' => "Job {$i}", 'started_on' => '2020-01-01'])->assertCreated();
        }
        $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Job 3', 'started_on' => '2020-01-01'])->assertUnprocessable()->assertJsonPath('error_code', 'freelance_profile_limit');
    }

    // -------------------------------------------------------------- certificates

    public function test_a_certificate_is_added_with_a_private_proof_only_its_owner_downloads(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $created = $this->postJson('/api/freelance/v1/profile/certificates', [
            'title' => 'AWS Developer', 'issuer' => 'Amazon', 'issued_on' => '2023-03-01', 'expires_on' => '2026-03-01', 'credential_url' => 'https://example.com/verify/123',
            'proof' => UploadedFile::fake()->createWithContent('aws.pdf', "%PDF-1.4\n%%EOF"),
        ])->assertCreated()->assertJsonPath('data.proof.name', 'proof.pdf')->assertJsonMissingPath('data.proof.url')->json('data');

        // It is on the private disk, not the public one, and no public URL is given out.
        $media = FreelancerCertificate::query()->findOrFail($created['id'])->proof();
        $this->assertSame('local', $media->disk);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->get("/api/freelance/v1/{$created['proof']['path']}")->assertOk()->assertDownload('proof.pdf');
        $this->getJson('/api/freelance/v1/profile/certificates')->assertOk()->assertJsonPath('data.0.title', 'AWS Developer');

        // Not someone else's.
        $this->freelancer('+966500000078');
        $this->get("/api/freelance/v1/{$created['proof']['path']}")->assertNotFound();
        $this->deleteJson("/api/freelance/v1/profile/certificates/{$created['id']}")->assertNotFound();
        $this->postJson("/api/freelance/v1/profile/certificates/{$created['id']}", ['title' => 'Hacked'])->assertNotFound();
        $this->getJson('/api/freelance/v1/profile/certificates')->assertJsonCount(0, 'data');
    }

    public function test_a_certificate_is_edited_its_proof_replaced_or_removed_and_deleted_with_its_file(): void
    {
        Storage::fake('local');
        $id = $this->postJson('/api/freelance/v1/profile/certificates', ['title' => 'Cert', 'issuer' => 'Body', 'proof' => UploadedFile::fake()->image('a.png')])->assertCreated()->json('data.id');

        $this->postJson("/api/freelance/v1/profile/certificates/{$id}", ['title' => 'Cert 2'])->assertOk()->assertJsonPath('data.title', 'Cert 2')->assertJsonPath('data.issuer', 'Body')->assertJsonPath('data.proof.name', 'proof.png');
        $this->postJson("/api/freelance/v1/profile/certificates/{$id}", ['proof' => UploadedFile::fake()->createWithContent('b.pdf', "%PDF-1.4\n%%EOF")])->assertOk()->assertJsonPath('data.proof.name', 'proof.pdf');
        $this->assertSame(1, FreelancerCertificate::query()->find($id)->media()->count(), 'replacing keeps one file');
        $this->postJson("/api/freelance/v1/profile/certificates/{$id}", ['remove_proof' => 1])->assertOk()->assertJsonPath('data.proof', null);

        $this->deleteJson("/api/freelance/v1/profile/certificates/{$id}")->assertOk();
        $this->assertSame(0, FreelancerCertificate::query()->count());
    }

    public function test_bad_certificates_are_refused(): void
    {
        $base = ['title' => 'Cert', 'issuer' => 'Body'];
        foreach ([
            ['credential_url' => 'javascript:alert(1)'], ['credential_url' => 'ftp://example.com/x'], ['credential_url' => 'not a url'],
            ['proof' => UploadedFile::fake()->create('virus.exe', 10)], ['proof' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')],
            ['issued_on' => now()->addDay()->toDateString()], ['issued_on' => '2024-05-01', 'expires_on' => '2024-01-01'],
        ] as $bad) {
            $this->postJson('/api/freelance/v1/profile/certificates', $base + $bad)->assertUnprocessable();
        }
        $this->postJson('/api/freelance/v1/profile/certificates', ['title' => 'No issuer'])->assertUnprocessable();
        $this->assertSame(0, FreelancerCertificate::query()->count());
    }

    // -------------------------------------------------------------- the rest

    public function test_the_lists_need_a_signed_in_freelancer_and_are_scoped_to_them(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/freelance/v1/profile/skills')->assertUnauthorized();
        $this->postJson('/api/freelance/v1/profile/certificates', ['title' => 'x', 'issuer' => 'y'])->assertUnauthorized();

        $this->freelancer('+966500000078');
        $mine = $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Mine', 'started_on' => '2020-01-01'])->json('data.id');
        $this->freelancer('+966500000079');
        $this->putJson("/api/freelance/v1/profile/experiences/{$mine}", ['title' => 'Stolen'])->assertNotFound();
        $this->deleteJson("/api/freelance/v1/profile/experiences/{$mine}")->assertNotFound();
        $this->getJson('/api/freelance/v1/profile/experiences')->assertJsonCount(0, 'data');
    }

    public function test_completeness_counts_the_new_steps_with_the_new_weights(): void
    {
        $weights = ['name' => 10, 'avatar' => 10, 'headline' => 10, 'bio' => 10, 'email' => 10, 'languages' => 5, 'experience' => 5, 'specialties' => 15, 'skills' => 15, 'work' => 5, 'portfolio' => 5];
        $this->assertSame(100, array_sum($weights));

        $first = $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness');
        $this->assertSame(10, $first['percent']);
        $this->assertEqualsCanonicalizing(['avatar', 'headline', 'bio', 'email', 'languages', 'experience', 'specialties', 'skills', 'work', 'portfolio'], $first['missing']);

        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->web->id], 'primary_id' => $this->web->id])->assertOk();
        $two = [$this->skill('PHP')->id, $this->skill('Vue')->id];
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => array_map(fn ($id) => ['skill_id' => $id], $two)])->assertOk();
        $this->assertContains('skills', $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness.missing'), 'fewer than '.FreelancerCompleteness::SKILLS_MIN.' skills do not count');
        $this->assertSame(10 + 15, $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness.percent'));

        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => array_map(fn ($id) => ['skill_id' => $id], [...$two, $this->skill('Go')->id])])->assertOk();
        $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Dev', 'started_on' => '2020-01-01'])->assertCreated();
        $this->assertSame(10 + 15 + 15 + 5, $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness.percent'));
    }

    public function test_deleting_the_account_clears_the_lists_and_gives_the_usage_back(): void
    {
        Storage::fake('local');
        $php = $this->skill('PHP');
        $this->putJson('/api/freelance/v1/profile/specialties', ['specialty_ids' => [$this->web->id], 'primary_id' => $this->web->id])->assertOk();
        $this->putJson('/api/freelance/v1/profile/skills', ['skills' => [['skill_id' => $php->id]]])->assertOk();
        $this->postJson('/api/freelance/v1/profile/experiences', ['title' => 'Dev', 'started_on' => '2020-01-01'])->assertCreated();
        $this->postJson('/api/freelance/v1/profile/certificates', ['title' => 'Cert', 'issuer' => 'Body', 'proof' => UploadedFile::fake()->image('a.png')])->assertCreated();
        $this->assertSame(1, $php->fresh()->usage_count);

        $this->deleteJson('/api/freelance/v1/account', ['confirm' => true])->assertOk();

        $this->assertSame(0, $php->fresh()->usage_count);
        $this->assertSame([0, 0, 0, 0], [\DB::table('freelancer_skills')->count(), \DB::table('freelancer_specialties')->count(), \DB::table('freelancer_experiences')->count(), \DB::table('freelancer_certificates')->count()]);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
