<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\Freelance\Events\FreelanceWorkroomChanged;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\FreelanceDelivery;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMilestone;
use Modules\Freelance\Models\FreelanceOffer;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceProjectEvent;
use Modules\Freelance\Models\FreelanceProjectMember;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\FreelanceWorkroomComment;
use Modules\Freelance\Models\FreelanceWorkroomFile;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

/**
 * FS-150b: the Workroom's File Hub - either side adds a file (its first version), the side that added it adds versions, renames and deletes it;
 * both sides read and open every version; notes go on a file, one of its versions or a delivery; uploads pass the delivery's size / type / scan
 * checks; the admin's caps stop new additions only; a freelancer's work on a part that is not approved stays a preview for the client when the
 * agreement carries rights terms; after the agreement ends everything is only read; a member of the client's team reads and a manager works.
 */
class FreelanceWorkroomFilesTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT = '/api/mobile/v1/freelance/projects';

    private const TEAM = '/api/mobile/v1/freelance/team';

    private const FREELANCER = '/api/freelance/v1/projects';

    private Country $saudi;

    private User $owner;

    private User $omar;

    private User $huda;

    private FreelanceProject $project;

    private Freelancer $sara;

    private Freelancer $lina;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'projects', 'bidding', 'chat', 'client_team', 'workroom'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $web = FreelanceSpecialty::query()->create(['status' => true, 'sort_order' => 1]);
        $web->translations()->createMany([['locale' => 'en', 'name' => 'Web'], ['locale' => 'ar', 'name' => 'ويب']]);
        $this->owner = $this->user('Mona', '+966500000001');
        $this->omar = $this->user('Omar', '+966500000002');
        $this->huda = $this->user('Huda', '+966500000003');
        $this->project = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $web->id, 'title' => 'A booking website',
            'description' => 'A small site where patients pick a doctor and a time.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);
        $this->sara = $this->freelancer('+966500000091', 'Sara');
        $this->lina = $this->freelancer('+966500000092', 'Lina');
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/offers", [
            'price_minor' => 350000, 'duration_days' => 21, 'details' => 'I built three booking sites like this; I will deliver in three steps with a demo each week.',
        ])->assertCreated();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $name, string $phone): User
    {
        return User::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
    }

    private function freelancer(string $phone, string $name): Freelancer
    {
        return Freelancer::query()->create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
    }

    private function as(User|Freelancer $who): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($who->refresh(), [], $who instanceof Freelancer ? 'freelance_api' : 'user_api');
    }

    private function join(User $who, string $role): FreelanceProjectMember
    {
        $this->as($this->owner);
        $this->postJson(self::CLIENT."/{$this->project->uuid}/team", ['phone' => $who->phone, 'role' => $role])->assertOk();
        $member = FreelanceProjectMember::query()->where('user_id', $who->id)->firstOrFail();
        $this->as($who);
        $this->postJson(self::TEAM."/invitations/{$member->uuid}/accept")->assertOk();

        return $member->refresh();
    }

    /** Sara chosen, two parts, the agreement active; returns the parts' uuids. */
    private function active(): array
    {
        $this->as($this->owner);
        $offer = FreelanceOffer::query()->where('freelancer_id', $this->sara->id)->firstOrFail();
        $this->getJson(self::CLIENT."/{$this->project->uuid}/offers")->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/offers/{$offer->uuid}/choose")->assertOk();
        $this->putJson(self::CLIENT."/{$this->project->uuid}/agreement/milestones", ['milestones' => [
            ['title' => 'Design', 'amount_minor' => 100000], ['title' => 'Build', 'amount_minor' => 250000],
        ]])->assertOk();
        $this->postJson(self::CLIENT."/{$this->project->uuid}/agreement/send")->assertOk();
        $this->as($this->sara);
        $this->postJson(self::FREELANCER."/{$this->project->uuid}/agreement/approve")->assertOk();

        return FreelanceMilestone::query()->orderBy('position')->pluck('uuid')->all();
    }

    private function files(string $prefix, string $suffix = ''): string
    {
        return $prefix."/{$this->project->uuid}/workroom/files".$suffix;
    }

    private function notes(string $prefix, string $suffix = ''): string
    {
        return $prefix."/{$this->project->uuid}/workroom/comments".$suffix;
    }

    private function pdf(string $name = 'brief.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF");
    }

    /** The client adds a file; returns its uuid. */
    private function clientFile(array $extra = []): string
    {
        $this->as($this->owner);

        return (string) $this->postJson($this->files(self::CLIENT), $extra + ['file' => $this->pdf(), 'title' => 'Project brief'])->assertCreated()->json('data.file.uuid');
    }

    private function freelancerFile(array $extra = []): string
    {
        $this->as($this->sara);

        return (string) $this->postJson($this->files(self::FREELANCER), $extra + ['file' => $this->pdf('wireframes.pdf'), 'title' => 'Wireframes'])->assertCreated()->json('data.file.uuid');
    }

    private function download(string $prefix, string $file, int $number = 1): string
    {
        return $this->files($prefix, "/{$file}/versions/{$number}/download");
    }

    private function told(User|Freelancer $who, string $event): int
    {
        return $who->notifications()->where('data->event', $event)->count();
    }

    public function test_the_file_hub_is_off_until_the_admin_opens_the_workroom_for_the_country(): void
    {
        $this->active();
        $file = $this->clientFile();
        FreelanceFeatureRule::query()->where('feature', 'workroom')->delete();
        FreelanceFeatures::forget();

        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf()])->assertForbidden();
        $this->getJson($this->download(self::CLIENT, $file))->assertForbidden();
        $this->getJson($this->notes(self::CLIENT).'?file='.$file)->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER))->assertForbidden()->assertJsonPath('error_code', 'freelance_feature_off');
        $this->postJson($this->notes(self::FREELANCER), ['file' => $file, 'body' => 'Hello'])->assertForbidden();
        // What was written stays; it is only not shown.
        $this->assertSame(1, FreelanceWorkroomFile::query()->count());
    }

    public function test_either_side_adds_a_file_with_its_first_version_and_the_other_side_opens_it(): void
    {
        $parts = $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);

        $this->as($this->sara);
        $created = $this->postJson($this->files(self::FREELANCER), ['file' => $this->pdf('wireframes.pdf'), 'title' => 'Wireframes', 'description' => 'First pass', 'note' => 'First draft', 'milestone' => $parts[0]])
            ->assertCreated()
            ->assertJsonPath('data.file.title', 'Wireframes')->assertJsonPath('data.file.description', 'First pass')->assertJsonPath('data.file.versions_count', 1)
            ->assertJsonPath('data.file.milestone.title', 'Design')->assertJsonPath('data.file.current.number', 1)->assertJsonPath('data.file.current.note', 'First draft')
            ->assertJsonPath('data.file.current.name', 'wireframes.pdf')->assertJsonPath('data.file.current.mime_type', 'application/pdf')
            ->assertJsonPath('data.file.current.by_side', 'freelancer')->assertJsonPath('data.file.current.preview_only', false)->assertJsonPath('data.file.mine', true)
            ->assertJsonPath('data.file.created_by_side', 'freelancer')->assertJsonPath('data.file.can.file_edit', true)->assertJsonPath('data.file.can.file_delete', true)
            ->assertJsonPath('data.file.can.version_add', true)->assertJsonPath('data.file.can.comment_add', true)
            ->assertJsonPath('data.summary.files', 1)->assertJsonPath('data.summary.versions', 1);
        $uuid = (string) $created->json('data.file.uuid');
        $this->assertGreaterThan(0, (int) $created->json('data.summary.storage_bytes'));
        $this->assertSame("projects/{$this->project->uuid}/workroom/files/{$uuid}/versions/1/download", $created->json('data.file.current.path'));

        // Kept as it was, on the private disk, never behind a public link.
        $media = FreelanceWorkroomFileVersion::query()->firstOrFail()->stored();
        $this->assertNotNull($media);
        $this->assertSame('local', $media->disk);
        $this->assertFileExists($media->getPath());
        $this->assertStringNotContainsString('/storage/', (string) $created->getContent());

        // The client is told once and sees it as the other side's file, with the buttons off.
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.file_shared'));
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.title', 'Wireframes')->assertJsonPath('data.files.0.mine', false)
            ->assertJsonPath('data.files.0.can.file_edit', false)->assertJsonPath('data.files.0.can.file_delete', false)->assertJsonPath('data.files.0.can.version_add', false)
            ->assertJsonPath('data.files.0.can.comment_add', true)->assertJsonPath('data.can.file_upload', true)->assertJsonPath('data.read_only', false)
            ->assertJsonPath('data.limit.max_files', null)->assertJsonPath('data.limit.max_storage_mb', null)->assertJsonPath('data.summary.files', 1)
            ->assertJsonPath('data.upload.max_kb', 20480)->assertJsonPath('data.upload.max_versions', 50);
        $this->getJson($this->files(self::CLIENT, "/{$uuid}"))->assertOk()->assertJsonCount(1, 'data.file.versions')->assertJsonPath('data.file.versions.0.number', 1);

        // It opens as a download that nothing runs and the browser does not guess at.
        $this->get($this->download(self::CLIENT, $uuid))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Disposition', 'attachment; filename=wireframes.pdf');
        $cache = (string) $this->get($this->download(self::CLIENT, $uuid))->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);

        // The client's own picture opens in place, and its title is the name of the file when none is given.
        $png = $this->postJson($this->files(self::CLIENT), ['file' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertCreated()
            ->assertJsonPath('data.file.title', 'logo')->assertJsonPath('data.file.current.is_image', true)->json('data.file.uuid');
        $this->get($this->download(self::CLIENT, (string) $png))->assertOk()->assertHeader('Content-Disposition', 'inline; filename=logo.png');
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.file_shared'));
        $this->as($this->sara);
        $this->get($this->download(self::FREELANCER, (string) $png))->assertOk();

        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 2);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->action === 'created' && $e->by === 'freelancer' && $e->uuid === $uuid
            && $e->projectUuid === $this->project->uuid && $e->broadcastWith()['kind'] === 'file');
    }

    public function test_the_side_that_added_a_file_adds_its_versions_and_every_old_version_stays_open(): void
    {
        $this->active();
        $uuid = $this->clientFile();
        $this->assertSame(1, $this->told($this->sara, 'freelance.workroom.file_shared'));

        $this->as($this->owner);
        $this->postJson($this->files(self::CLIENT, "/{$uuid}/versions"), ['file' => $this->pdf('brief-v2.pdf'), 'note' => 'Added the colours'])->assertCreated()
            ->assertJsonPath('data.file.versions_count', 2)->assertJsonPath('data.file.current.number', 2)->assertJsonPath('data.file.current.note', 'Added the colours')
            ->assertJsonPath('data.file.current.name', 'brief-v2.pdf')->assertJsonCount(2, 'data.file.versions')
            ->assertJsonPath('data.file.versions.0.number', 2)->assertJsonPath('data.file.versions.1.number', 1)->assertJsonPath('data.summary.versions', 2)->assertJsonPath('data.summary.files', 1);

        $this->get($this->download(self::CLIENT, $uuid, 1))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=brief.pdf');
        $this->get($this->download(self::CLIENT, $uuid, 2))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=brief-v2.pdf');
        $this->get($this->download(self::CLIENT, $uuid, 3))->assertNotFound();
        $this->assertSame(2, FreelanceWorkroomFileVersion::query()->count());
        $this->assertSame(2, Media::query()->where('model_type', FreelanceWorkroomFileVersion::class)->count());

        // Sara is told about the new version, reads everything, and can add nothing to a file that is not hers.
        $this->as($this->sara);
        $this->assertSame(2, $this->told($this->sara, 'freelance.workroom.file_shared'));
        $this->getJson($this->files(self::FREELANCER, "/{$uuid}"))->assertOk()->assertJsonPath('data.file.can.version_add', false)->assertJsonPath('data.file.mine', false)->assertJsonCount(2, 'data.file.versions');
        $this->get($this->download(self::FREELANCER, $uuid, 1))->assertOk();
        $this->postJson($this->files(self::FREELANCER, "/{$uuid}/versions"), ['file' => $this->pdf('mine.pdf')])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_file_forbidden');
        $this->assertSame(2, FreelanceWorkroomFileVersion::query()->count());
    }

    public function test_one_file_keeps_a_bounded_number_of_versions(): void
    {
        $this->active();
        $uuid = $this->clientFile();
        FreelanceWorkroomFile::query()->update(['versions_count' => 50]);

        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT, "/{$uuid}"))->assertOk()->assertJsonPath('data.file.can.version_add', false);
        $this->postJson($this->files(self::CLIENT, "/{$uuid}/versions"), ['file' => $this->pdf('one-more.pdf')])->assertStatus(422)
            ->assertJsonPath('error_code', 'freelance_workroom_versions_limit')->assertJsonPath('data.max', 50);
        $this->assertSame(1, FreelanceWorkroomFileVersion::query()->count());
    }

    public function test_the_writer_renames_ties_and_deletes_a_file_and_the_other_side_can_not(): void
    {
        $parts = $this->active();
        $uuid = $this->clientFile(['title' => 'Brief']);

        $this->as($this->owner);
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['title' => 'Project brief', 'description' => 'Everything about the site'])->assertOk()
            ->assertJsonPath('data.file.title', 'Project brief')->assertJsonPath('data.file.description', 'Everything about the site');
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['milestone' => $parts[1]])->assertOk()->assertJsonPath('data.file.milestone.title', 'Build');
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['milestone' => null])->assertOk()->assertJsonPath('data.file.milestone', null);
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['milestone' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_milestone_invalid');
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['title' => '   '])->assertUnprocessable();
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['title' => str_repeat('a', 161)])->assertUnprocessable();
        $this->putJson($this->files(self::CLIENT, "/{$uuid}"), ['title' => '<b></b>'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_title_required');
        $this->getJson($this->files(self::CLIENT, "/{$uuid}"))->assertJsonPath('data.file.title', 'Project brief');

        $this->as($this->sara);
        $this->putJson($this->files(self::FREELANCER, "/{$uuid}"), ['title' => 'Mine now'])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_file_forbidden');
        $this->deleteJson($this->files(self::FREELANCER, "/{$uuid}"))->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_file_forbidden');
        $this->assertSame('Project brief', FreelanceWorkroomFile::query()->firstOrFail()->title);

        // A note on it, then the writer deletes the file: it disappears with its note, but is kept for the record.
        $this->postJson($this->notes(self::FREELANCER), ['file' => $uuid, 'body' => 'Looks good'])->assertCreated();
        $this->as($this->owner);
        $this->deleteJson($this->files(self::CLIENT, "/{$uuid}"))->assertOk()->assertJsonPath('data.summary.files', 0)->assertJsonPath('data.summary.storage_bytes', 0);
        $this->getJson($this->files(self::CLIENT, "/{$uuid}"))->assertNotFound();
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonCount(0, 'data.files');
        $this->get($this->download(self::CLIENT, $uuid))->assertNotFound();
        $this->getJson($this->notes(self::CLIENT).'?file='.$uuid)->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER, "/{$uuid}"))->assertNotFound();
        $this->assertSame(1, FreelanceWorkroomFile::withTrashed()->count());
        $this->assertSame(1, FreelanceWorkroomFileVersion::query()->count());
        $this->assertSame(1, FreelanceWorkroomComment::query()->count());
        $this->assertFileExists(FreelanceWorkroomFileVersion::query()->firstOrFail()->stored()->getPath());
    }

    public function test_the_text_of_a_file_is_cleaned_and_checked(): void
    {
        $this->active();
        $this->as($this->owner);
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf(), 'title' => '<script>x</script>Brief <i>1</i>', 'description' => '<b>All</b> the details', 'note' => '<u>first</u>'])->assertCreated()
            ->assertJsonPath('data.file.title', 'xBrief 1')->assertJsonPath('data.file.description', 'All the details')->assertJsonPath('data.file.current.note', 'first');
        // A title made only of markup falls back to the name of the file.
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf('Site map.pdf'), 'title' => '<b></b>'])->assertCreated()->assertJsonPath('data.file.title', 'Site map');
        // A folder or markup in the file's name never reaches the record.
        $this->postJson($this->files(self::CLIENT), ['file' => UploadedFile::fake()->createWithContent('..\\..\\secret.pdf', "%PDF-1.4\n%%EOF")])->assertCreated()
            ->assertJsonPath('data.file.current.name', 'secret.pdf');
    }

    public function test_uploads_follow_the_deliverys_size_type_and_scan_rules_and_a_refused_one_leaves_nothing(): void
    {
        $this->active();
        $this->as($this->owner);
        $url = $this->files(self::CLIENT);

        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['file' => 'not a file'])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->create('page.html', 1, 'text/html')])->assertUnprocessable();
        // A program behind a friendly name, and a script behind a pdf's: the real bytes are checked.
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('brief.pdf', "<?php echo 'hi';")])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('photo.png', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff")])->assertUnprocessable();
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('report.pdf.exe', "%PDF-1.4\n%%EOF")])->assertUnprocessable();

        config(['freelance.upload_max_kb' => 10]);
        $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('big.txt', str_repeat('a', 20 * 1024))])->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('data.upload.max_kb', 10);
        config(['freelance.upload_max_kb' => 20480]);

        $this->postJson($url, ['file' => $this->pdf(), 'title' => str_repeat('a', 161)])->assertUnprocessable();
        $this->postJson($url, ['file' => $this->pdf(), 'description' => str_repeat('a', 1001)])->assertUnprocessable();
        $this->postJson($url, ['file' => $this->pdf(), 'note' => str_repeat('a', 501)])->assertUnprocessable();
        $this->postJson($url, ['file' => $this->pdf(), 'milestone' => 'not-a-uuid'])->assertUnprocessable();
        $this->postJson($url, ['file' => $this->pdf(), 'milestone' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_milestone_invalid');

        // Nothing was kept - no file, no version, no stored bytes.
        $this->assertSame(0, FreelanceWorkroomFile::withTrashed()->count());
        $this->assertSame(0, FreelanceWorkroomFileVersion::query()->count());
        $this->assertSame(0, Media::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // The same rules guard a new version.
        $uuid = $this->clientFile();
        $this->postJson($this->files(self::CLIENT, "/{$uuid}/versions"), ['file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])->assertUnprocessable();
        $this->postJson($this->files(self::CLIENT, "/{$uuid}/versions"), [])->assertUnprocessable();
        $this->assertSame(1, FreelanceWorkroomFileVersion::query()->count());
        $this->assertSame(1, FreelanceWorkroomFile::query()->firstOrFail()->versions_count);
    }

    public function test_the_admins_caps_stop_new_files_and_new_storage_only(): void
    {
        $this->active();
        $this->as($this->owner);
        // No cap at first.
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonPath('data.limit.max_files', null)->assertJsonPath('data.limit.max_storage_mb', null);
        $first = $this->clientFile(['title' => 'First']);

        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.files_max' => 1]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonPath('data.limit.max_files', 1);
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_files_limit')->assertJsonPath('data.max', 1);
        $this->as($this->sara);
        $this->postJson($this->files(self::FREELANCER), ['file' => $this->pdf()])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_files_limit');
        // A new version of a file that is there, a rename and a note are still welcome; a place frees up when a file is deleted.
        $this->as($this->owner);
        $this->postJson($this->files(self::CLIENT, "/{$first}/versions"), ['file' => $this->pdf('v2.pdf')])->assertCreated();
        $this->putJson($this->files(self::CLIENT, "/{$first}"), ['title' => 'Still here'])->assertOk();
        $this->postJson($this->notes(self::CLIENT), ['file' => $first, 'body' => 'Noted'])->assertCreated();
        $this->deleteJson($this->files(self::CLIENT, "/{$first}"))->assertOk();
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf()])->assertCreated();

        // The storage cap counts the bytes of every version of the files that are not deleted.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.files_max' => null, 'workroom.storage_mb' => 1]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertJsonPath('data.limit.max_files', null)->assertJsonPath('data.limit.max_storage_mb', 1);
        $big = fn (string $name) => UploadedFile::fake()->createWithContent($name, str_repeat('a', 700 * 1024));
        $one = $this->postJson($this->files(self::CLIENT), ['file' => $big('one.txt')])->assertCreated()->assertJsonPath('data.summary.files', 2)->json('data.file.uuid');
        $this->assertGreaterThan(700 * 1024, (int) $this->getJson($this->files(self::CLIENT))->json('data.summary.storage_bytes'));
        $this->postJson($this->files(self::CLIENT), ['file' => $big('two.txt')])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_storage_limit')->assertJsonPath('data.max_mb', 1);
        $this->postJson($this->files(self::CLIENT, "/{$one}/versions"), ['file' => $big('one-v2.txt')])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_storage_limit');
        $this->as($this->sara);
        $this->postJson($this->files(self::FREELANCER), ['file' => $big('three.txt')])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_storage_limit');
        // Small files still fit, and deleting the big one gives the room back.
        $this->postJson($this->files(self::FREELANCER), ['file' => $this->pdf('small.pdf')])->assertCreated();
        $this->as($this->owner);
        $this->deleteJson($this->files(self::CLIENT, "/{$one}"))->assertOk();
        $this->postJson($this->files(self::CLIENT), ['file' => $big('two.txt')])->assertCreated();

        // An empty value in the admin's form means no cap again.
        app(FreelancePolicies::class)->save($this->saudi->id, null, ['workroom.storage_mb' => null]);
        FreelancePolicies::forget();
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertJsonPath('data.limit.max_storage_mb', null);
        $this->postJson($this->files(self::CLIENT), ['file' => $big('four.txt')])->assertCreated();
    }

    public function test_the_caps_are_validated_by_the_policy(): void
    {
        foreach (['workroom.files_max', 'workroom.storage_mb'] as $key) {
            try {
                app(FreelancePolicies::class)->save($this->saudi->id, null, [$key => 0]);
                $this->fail("{$key} accepted 0");
            } catch (FreelanceException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_list_filters_and_pages_and_shows_what_changed_last_first(): void
    {
        $parts = $this->active();
        $a = $this->clientFile(['title' => 'Alpha brief', 'milestone' => $parts[0]]);
        $b = $this->freelancerFile(['title' => 'Beta wireframes', 'milestone' => $parts[1]]);
        $c = $this->clientFile(['title' => 'Gamma logo']);
        // Alpha gets a new version a minute later, so it moves to the top.
        Carbon::setTestNow(now()->addMinute());
        $this->as($this->owner);
        $this->postJson($this->files(self::CLIENT, "/{$a}/versions"), ['file' => $this->pdf('alpha-2.pdf')])->assertCreated();

        $this->assertSame([$a, $c, $b], collect($this->getJson($this->files(self::CLIENT))->assertOk()->json('data.files'))->pluck('uuid')->all());
        $this->getJson($this->files(self::CLIENT).'?milestone='.$parts[0])->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.uuid', $a);
        $this->getJson($this->files(self::CLIENT).'?mine=1')->assertJsonCount(2, 'data.files');
        $this->getJson($this->files(self::CLIENT).'?search=wire')->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.uuid', $b);
        $this->getJson($this->files(self::CLIENT).'?search=%')->assertJsonCount(0, 'data.files');
        $this->getJson($this->files(self::CLIENT).'?per_page=2')->assertJsonCount(2, 'data.files')->assertJsonPath('pagination.total', 3)->assertJsonPath('pagination.has_more_pages', true);
        $this->getJson($this->files(self::CLIENT).'?per_page=500')->assertUnprocessable();
        $this->getJson($this->files(self::CLIENT).'?milestone=nope')->assertUnprocessable();
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER).'?mine=1')->assertJsonCount(1, 'data.files')->assertJsonPath('data.files.0.uuid', $b);
    }

    public function test_notes_go_on_a_file_one_of_its_versions_or_a_delivery_and_both_sides_read_them(): void
    {
        $parts = $this->active();
        $file = $this->clientFile(['milestone' => $parts[0]]);
        $delivery = FreelanceDelivery::query()->create(['milestone_id' => FreelanceMilestone::query()->where('uuid', $parts[0])->value('id'), 'number' => 1, 'note' => 'First cut', 'created_at' => now()]);
        Event::fake([FreelanceWorkroomChanged::class]);

        $this->as($this->sara);
        $first = (string) $this->postJson($this->notes(self::FREELANCER), ['file' => $file, 'body' => 'Is this the final brief?'])->assertCreated()
            ->assertJsonPath('data.comment.body', 'Is this the final brief?')->assertJsonPath('data.comment.subject.type', 'file')->assertJsonPath('data.comment.subject.uuid', $file)
            ->assertJsonPath('data.comment.version', null)->assertJsonPath('data.comment.author_side', 'freelancer')->assertJsonPath('data.comment.mine', true)
            ->assertJsonPath('data.comment.can.comment_edit', true)->assertJsonPath('data.comment.can.comment_delete', true)->json('data.comment.uuid');
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.comment_added'));

        $this->as($this->owner);
        $this->postJson($this->notes(self::CLIENT), ['file' => $file, 'version' => 1, 'body' => 'Yes, version one'])->assertCreated()->assertJsonPath('data.comment.version', 1)->assertJsonPath('data.comment.mine', true);
        $this->postJson($this->notes(self::CLIENT), ['delivery' => $delivery->uuid, 'body' => 'Please check the footer'])->assertCreated()
            ->assertJsonPath('data.comment.subject.type', 'delivery')->assertJsonPath('data.comment.subject.uuid', $delivery->uuid)->assertJsonPath('data.comment.version', null);
        $this->assertSame(2, $this->told($this->sara, 'freelance.workroom.comment_added'));
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.comment_added'));

        // Oldest first; a note someone else wrote is not mine and has its buttons off.
        $this->getJson($this->notes(self::CLIENT).'?file='.$file)->assertOk()->assertJsonCount(2, 'data.comments')->assertJsonPath('data.comments.0.uuid', $first)
            ->assertJsonPath('data.comments.0.body', 'Is this the final brief?')->assertJsonPath('data.comments.0.mine', false)->assertJsonPath('data.comments.0.can.comment_edit', false)
            ->assertJsonPath('data.comments.1.mine', true)->assertJsonPath('data.can.comment_add', true)->assertJsonPath('data.read_only', false);
        $this->getJson($this->notes(self::CLIENT).'?file='.$file.'&version=1')->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.body', 'Yes, version one');
        $this->getJson($this->notes(self::CLIENT).'?delivery='.$delivery->uuid)->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.subject.type', 'delivery');
        $this->getJson($this->files(self::CLIENT, "/{$file}"))->assertJsonPath('data.file.comments_count', 2);
        $this->as($this->sara);
        $this->getJson($this->notes(self::FREELANCER).'?delivery='.$delivery->uuid)->assertOk()->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.mine', false);

        // The live screens are told which file or delivery the note is on, and no text travels.
        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 3);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'comment' && $e->action === 'created' && $e->uuid === $delivery->uuid && $e->by === 'client');
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->uuid === $file && ! array_key_exists('body', $e->broadcastWith()));
    }

    public function test_a_note_is_checked_cleaned_and_must_be_on_one_real_thing_of_this_workroom(): void
    {
        $parts = $this->active();
        $file = $this->clientFile();
        $delivery = FreelanceDelivery::query()->create(['milestone_id' => FreelanceMilestone::query()->where('uuid', $parts[0])->value('id'), 'number' => 1, 'note' => 'First cut', 'created_at' => now()]);
        $this->as($this->owner);
        $url = $this->notes(self::CLIENT);

        $this->postJson($url, ['body' => 'On nothing'])->assertUnprocessable();
        $this->postJson($url, ['file' => $file, 'delivery' => $delivery->uuid, 'body' => 'On two things'])->assertUnprocessable();
        $this->postJson($url, ['file' => $file])->assertUnprocessable();
        $this->postJson($url, ['file' => $file, 'body' => '   '])->assertUnprocessable();
        $this->postJson($url, ['file' => $file, 'body' => str_repeat('a', 2001)])->assertUnprocessable();
        $this->postJson($url, ['file' => 'not-a-uuid', 'body' => 'Hello'])->assertUnprocessable();
        $this->postJson($url, ['file' => $file, 'version' => 0, 'body' => 'Hello'])->assertUnprocessable();
        $this->postJson($url, ['file' => $file, 'body' => '<b></b>'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_comment_empty');
        $this->postJson($url, ['file' => $file, 'version' => 9, 'body' => 'No such version'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_version_invalid');
        // Not a file or a delivery of this Workroom: nothing to write on.
        $this->postJson($url, ['file' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'Hello'])->assertNotFound();
        $this->postJson($url, ['delivery' => (string) \Illuminate\Support\Str::uuid(), 'body' => 'Hello'])->assertNotFound();
        $this->getJson($url)->assertUnprocessable();
        $this->getJson($url.'?file='.$file.'&delivery='.$delivery->uuid)->assertUnprocessable();
        $this->getJson($url.'?file='.(string) \Illuminate\Support\Str::uuid())->assertNotFound();
        $this->assertSame(0, FreelanceWorkroomComment::query()->count());

        // Markup is stripped; a note on a delivery ignores a version number.
        $this->postJson($url, ['file' => $file, 'body' => '<script>x</script>Hi <b>there</b>'])->assertCreated()->assertJsonPath('data.comment.body', 'xHi there');
        $this->postJson($url, ['delivery' => $delivery->uuid, 'version' => 5, 'body' => 'On the delivery'])->assertCreated()->assertJsonPath('data.comment.version', null);
        $this->assertSame(2, FreelanceWorkroomComment::query()->count());
    }

    public function test_a_note_is_edited_and_deleted_by_the_side_that_wrote_it_only(): void
    {
        $this->active();
        $file = $this->clientFile();
        $this->as($this->sara);
        $note = (string) $this->postJson($this->notes(self::FREELANCER), ['file' => $file, 'body' => 'First wording'])->assertCreated()->json('data.comment.uuid');
        $this->assertNull(FreelanceWorkroomComment::query()->firstOrFail()->edited_at);

        $this->putJson($this->notes(self::FREELANCER, "/{$note}"), ['body' => 'Better wording'])->assertOk()->assertJsonPath('data.comment.body', 'Better wording');
        $this->assertNotNull($this->getJson($this->notes(self::FREELANCER).'?file='.$file)->json('data.comments.0.edited_at'));
        $this->putJson($this->notes(self::FREELANCER, "/{$note}"), ['body' => '   '])->assertUnprocessable();
        $this->putJson($this->notes(self::FREELANCER, "/{$note}"), ['body' => '<b></b>'])->assertStatus(422)->assertJsonPath('error_code', 'freelance_workroom_comment_empty');

        $this->as($this->owner);
        $this->putJson($this->notes(self::CLIENT, "/{$note}"), ['body' => 'Mine now'])->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_comment_forbidden');
        $this->deleteJson($this->notes(self::CLIENT, "/{$note}"))->assertForbidden()->assertJsonPath('error_code', 'freelance_workroom_comment_forbidden');
        $this->assertSame('Better wording', FreelanceWorkroomComment::query()->firstOrFail()->body);

        $this->as($this->sara);
        $this->deleteJson($this->notes(self::FREELANCER, "/{$note}"))->assertOk();
        $this->getJson($this->notes(self::FREELANCER).'?file='.$file)->assertJsonCount(0, 'data.comments');
        $this->deleteJson($this->notes(self::FREELANCER, "/{$note}"))->assertNotFound();
        $this->putJson($this->notes(self::FREELANCER, "/{$note}"), ['body' => 'Back'])->assertNotFound();
        $this->assertSame(1, FreelanceWorkroomComment::withTrashed()->count());
        $this->assertSame(0, FreelanceWorkroomComment::query()->count());
    }

    public function test_the_files_and_notes_exist_while_the_agreement_is_active_and_are_only_read_after_it(): void
    {
        // Not an agreement yet: nothing to open.
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertNotFound();
        $this->getJson($this->notes(self::CLIENT).'?file='.(string) \Illuminate\Support\Str::uuid())->assertNotFound();
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER))->assertNotFound();

        $this->active();
        $mine = $this->clientFile();
        $note = (string) $this->postJson($this->notes(self::CLIENT), ['file' => $mine, 'body' => 'A first note'])->assertCreated()->json('data.comment.uuid');
        FreelanceAgreement::query()->update(['status' => FreelanceAgreement::COMPLETED]);

        foreach ([[self::CLIENT, $this->owner], [self::FREELANCER, $this->sara]] as [$prefix, $who]) {
            $this->as($who);
            $this->getJson($this->files($prefix))->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.read_only', true)->assertJsonPath('data.can.file_upload', false)
                ->assertJsonPath('data.files.0.can.file_edit', false)->assertJsonPath('data.files.0.can.file_delete', false)->assertJsonPath('data.files.0.can.version_add', false)
                ->assertJsonPath('data.files.0.can.comment_add', false);
            $this->get($this->download($prefix, $mine))->assertOk();
            $this->getJson($this->notes($prefix).'?file='.$mine)->assertOk()->assertJsonCount(1, 'data.comments')->assertJsonPath('data.read_only', true)->assertJsonPath('data.can.comment_add', false)
                ->assertJsonPath('data.comments.0.can.comment_edit', false)->assertJsonPath('data.comments.0.can.comment_delete', false);

            $this->postJson($this->files($prefix), ['file' => $this->pdf()])->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_not_active');
            $this->postJson($this->files($prefix, "/{$mine}/versions"), ['file' => $this->pdf()])->assertStatus(409);
            $this->putJson($this->files($prefix, "/{$mine}"), ['title' => 'Too late now'])->assertStatus(409);
            $this->deleteJson($this->files($prefix, "/{$mine}"))->assertStatus(409);
            $this->postJson($this->notes($prefix), ['file' => $mine, 'body' => 'Too late'])->assertStatus(409)->assertJsonPath('error_code', 'freelance_workroom_not_active');
            $this->putJson($this->notes($prefix, "/{$note}"), ['body' => 'Too late'])->assertStatus(409);
            $this->deleteJson($this->notes($prefix, "/{$note}"))->assertStatus(409);
        }
        $this->assertSame(1, FreelanceWorkroomFile::query()->count());
        $this->assertSame(1, FreelanceWorkroomComment::query()->count());
        $this->assertSame('A first note', FreelanceWorkroomComment::query()->firstOrFail()->body);
    }

    public function test_a_stranger_reaches_no_file_and_no_note(): void
    {
        $this->active();
        $file = $this->clientFile();
        $note = (string) $this->postJson($this->notes(self::CLIENT), ['file' => $file, 'body' => 'A first note'])->assertCreated()->json('data.comment.uuid');

        // Another freelancer.
        $this->as($this->lina);
        $this->getJson($this->files(self::FREELANCER))->assertNotFound();
        $this->getJson($this->files(self::FREELANCER, "/{$file}"))->assertNotFound();
        $this->get($this->download(self::FREELANCER, $file))->assertNotFound();
        $this->postJson($this->files(self::FREELANCER), ['file' => $this->pdf()])->assertNotFound();
        $this->getJson($this->notes(self::FREELANCER).'?file='.$file)->assertNotFound();
        $this->postJson($this->notes(self::FREELANCER), ['file' => $file, 'body' => 'Hello'])->assertNotFound();
        $this->deleteJson($this->notes(self::FREELANCER, "/{$note}"))->assertNotFound();

        // A client who is neither the owner nor on the team.
        $this->as($this->huda);
        $this->getJson($this->files(self::CLIENT))->assertNotFound();
        $this->get($this->download(self::CLIENT, $file))->assertNotFound();
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf()])->assertNotFound();
        $this->getJson($this->notes(self::CLIENT).'?file='.$file)->assertNotFound();
        $this->putJson($this->notes(self::CLIENT, "/{$note}"), ['body' => 'Hello'])->assertNotFound();

        $this->assertSame(1, FreelanceWorkroomFile::query()->count());
        $this->assertSame('A first note', FreelanceWorkroomComment::query()->firstOrFail()->body);
    }

    public function test_a_file_of_one_project_is_not_reachable_through_another_projects_workroom(): void
    {
        $this->active();
        $file = $this->clientFile();
        // A second project of the same owner with Sara, its own agreement.
        $other = FreelanceProject::query()->create([
            'client_id' => $this->owner->id, 'country_id' => $this->saudi->id, 'freelance_specialty_id' => $this->project->freelance_specialty_id, 'title' => 'A second site',
            'description' => 'Another small site for a clinic.', 'budget_type' => 'fixed', 'budget_min_minor' => 400000, 'currency_code' => 'SAR',
            'duration_days' => 30, 'visibility' => 'public', 'status' => FreelanceProjectStatus::Published, 'published_at' => now(), 'offers_deadline_at' => now()->addDays(14),
        ]);

        $this->as($this->owner);
        // No agreement on the second project: nothing to open, and the first project's file uuid means nothing there.
        $this->getJson(self::CLIENT."/{$other->uuid}/workroom/files/{$file}")->assertNotFound();
        $this->get(self::CLIENT."/{$other->uuid}/workroom/files/{$file}/versions/1/download")->assertNotFound();
    }

    public function test_a_member_of_the_clients_team_reads_and_a_manager_works_on_the_files(): void
    {
        $this->active();
        $this->join($this->omar, 'manager');
        $this->join($this->huda, 'collaborator');
        $fromSara = $this->freelancerFile();
        // The owner and the manager (who can work on files) are told; the collaborator (who only follows) is not.
        $this->assertSame(1, $this->told($this->owner, 'freelance.workroom.file_shared'));
        $this->assertSame(1, $this->told($this->omar, 'freelance.workroom.file_shared'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.workroom.file_shared'));
        $this->postJson($this->notes(self::FREELANCER), ['file' => $fromSara, 'body' => 'Please have a look'])->assertCreated();
        $this->assertSame(1, $this->told($this->omar, 'freelance.workroom.comment_added'));
        $this->assertSame(0, $this->told($this->huda, 'freelance.workroom.comment_added'));

        // The collaborator reads and opens, and sees every button off.
        $this->as($this->huda);
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonCount(1, 'data.files')->assertJsonPath('data.can.file_upload', false)
            ->assertJsonPath('data.files.0.can.comment_add', false)->assertJsonPath('data.files.0.can.file_edit', false);
        $this->getJson($this->files(self::CLIENT, "/{$fromSara}"))->assertOk()->assertJsonPath('data.file.can.comment_add', false);
        $this->get($this->download(self::CLIENT, $fromSara))->assertOk();
        $this->getJson($this->notes(self::CLIENT).'?file='.$fromSara)->assertOk()->assertJsonCount(1, 'data.comments')->assertJsonPath('data.can.comment_add', false);
        $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf()])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->postJson($this->notes(self::CLIENT), ['file' => $fromSara, 'body' => 'Let me write one'])->assertForbidden()->assertJsonPath('error_code', 'freelance_team_forbidden');
        $this->putJson($this->files(self::CLIENT, "/{$fromSara}"), ['title' => 'Mine'])->assertForbidden();
        $this->deleteJson($this->files(self::CLIENT, "/{$fromSara}"))->assertForbidden();

        // The manager works on them as the owner, and the record says who.
        $this->as($this->omar);
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonPath('data.can.file_upload', true)->assertJsonPath('data.files.0.can.comment_add', true);
        $mine = (string) $this->postJson($this->files(self::CLIENT), ['file' => $this->pdf('omar.pdf'), 'title' => 'From Omar'])->assertCreated()
            ->assertJsonPath('data.file.can.file_edit', true)->assertJsonPath('data.file.can.version_add', true)->json('data.file.uuid');
        $this->postJson($this->files(self::CLIENT, "/{$mine}/versions"), ['file' => $this->pdf('omar-2.pdf')])->assertCreated()->assertJsonPath('data.file.versions_count', 2);
        $this->postJson($this->notes(self::CLIENT), ['file' => $mine, 'body' => 'A note from Omar'])->assertCreated();
        $this->putJson($this->files(self::CLIENT, "/{$mine}"), ['title' => 'From Omar, renamed'])->assertOk();

        $created = FreelanceProjectEvent::query()->where('type', 'workroom.file.uploaded')->where('actor_type', 'client')->firstOrFail();
        $this->assertSame($this->owner->id, (int) $created->actor_id);
        $this->assertSame($this->omar->id, (int) $created->acted_by_user_id);
        $this->assertSame($this->omar->id, (int) FreelanceProjectEvent::query()->where('type', 'workroom.comment.created')->where('actor_type', 'client')->firstOrFail()->acted_by_user_id);

        // The file is the owner's side's: the owner changes it as well, and Sara tells it from her own.
        $this->as($this->owner);
        $this->putJson($this->files(self::CLIENT, "/{$mine}"), ['title' => 'From the owner'])->assertOk();
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER))->assertJsonCount(2, 'data.files');
        $this->getJson($this->files(self::FREELANCER).'?mine=1')->assertJsonCount(1, 'data.files');
        // The manager's file and its new version both reached Sara (the owner's rename did not).
        $this->assertSame(2, $this->told($this->sara, 'freelance.workroom.file_shared'));
    }

    public function test_a_freelancers_work_on_a_part_that_is_not_approved_is_a_preview_for_the_client_when_rights_apply(): void
    {
        $parts = $this->active();
        $this->as($this->sara);
        $png = (string) $this->postJson($this->files(self::FREELANCER), ['file' => UploadedFile::fake()->image('draft.png', 800, 600), 'title' => 'Draft', 'milestone' => $parts[0]])->assertCreated()->json('data.file.uuid');
        $pdf = $this->freelancerFile(['title' => 'Source', 'milestone' => $parts[0]]);
        $free = $this->freelancerFile(['file' => $this->pdf('questions.pdf'), 'title' => 'Questions']);

        // No rights terms on the agreement: nothing is held back.
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT, "/{$pdf}"))->assertOk()->assertJsonPath('data.file.current.preview_only', false);
        $this->get($this->download(self::CLIENT, $pdf))->assertOk();

        FreelanceAgreement::query()->firstOrFail()->forceFill(['rights' => ['model' => 'transfer', 'commercial' => true, 'exclusive' => true, 'term_months' => null, 'territory' => 'worldwide', 'portfolio' => true]])->save();

        // Sara opens her own work as it is.
        $this->as($this->sara);
        $this->getJson($this->files(self::FREELANCER, "/{$pdf}"))->assertJsonPath('data.file.current.preview_only', false);
        $this->get($this->download(self::FREELANCER, $pdf))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=wireframes.pdf');
        $this->get($this->download(self::FREELANCER, $png))->assertOk()->assertHeader('Content-Disposition', 'inline; filename=draft.png');

        // The client gets a small picture of a picture, and nothing of anything else, until the part is approved; files tied to no part are plain.
        $this->as($this->owner);
        $this->getJson($this->files(self::CLIENT))->assertOk()->assertJsonCount(3, 'data.files');
        $this->getJson($this->files(self::CLIENT, "/{$png}"))->assertJsonPath('data.file.current.preview_only', true)->assertJsonPath('data.file.versions.0.preview_only', true);
        $this->getJson($this->files(self::CLIENT, "/{$pdf}"))->assertJsonPath('data.file.current.preview_only', true);
        $this->getJson($this->files(self::CLIENT, "/{$free}"))->assertJsonPath('data.file.current.preview_only', false);
        $this->get($this->download(self::CLIENT, $png))->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Content-Disposition', 'inline')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->download(self::CLIENT, $pdf), ['Accept' => 'application/json'])->assertForbidden()->assertJsonPath('error_code', 'freelance_rights_preview_only');
        $this->get($this->download(self::CLIENT, $free))->assertOk();

        // The client's own file on the same part is plain for him.
        $own = $this->clientFile(['file' => $this->pdf('reference.pdf'), 'title' => 'Reference', 'milestone' => $parts[0]]);
        $this->getJson($this->files(self::CLIENT, "/{$own}"))->assertJsonPath('data.file.current.preview_only', false);
        $this->get($this->download(self::CLIENT, $own))->assertOk();

        // Once the part is approved the work opens in full.
        FreelanceMilestone::query()->where('uuid', $parts[0])->update(['status' => 'approved']);
        $this->getJson($this->files(self::CLIENT, "/{$pdf}"))->assertJsonPath('data.file.current.preview_only', false);
        $this->get($this->download(self::CLIENT, $pdf))->assertOk();
        $this->get($this->download(self::CLIENT, $png))->assertOk()->assertHeader('Content-Disposition', 'inline; filename=draft.png');
    }

    public function test_every_step_is_on_the_project_record_and_tells_the_live_screens_once_and_stays_off_the_timeline(): void
    {
        $this->active();
        Event::fake([FreelanceWorkroomChanged::class]);

        $file = $this->clientFile();
        $this->postJson($this->files(self::CLIENT, "/{$file}/versions"), ['file' => $this->pdf('brief-2.pdf')])->assertCreated();
        $this->putJson($this->files(self::CLIENT, "/{$file}"), ['title' => 'Project brief v2'])->assertOk();
        $note = (string) $this->postJson($this->notes(self::CLIENT), ['file' => $file, 'version' => 2, 'body' => 'Top secret wording'])->assertCreated()->json('data.comment.uuid');
        $this->putJson($this->notes(self::CLIENT, "/{$note}"), ['body' => 'Other secret wording'])->assertOk();
        $this->deleteJson($this->notes(self::CLIENT, "/{$note}"))->assertOk();
        $this->deleteJson($this->files(self::CLIENT, "/{$file}"))->assertOk();

        // They are the Workroom's record, not the project's own history the client's page lists.
        $types = collect($this->getJson(self::CLIENT."/{$this->project->uuid}")->assertOk()->json('data.timeline'))->pluck('type');
        $this->assertNotEmpty($types);
        $this->assertTrue($types->every(fn ($t) => ! str_starts_with((string) $t, 'workroom.')));

        $rows = FreelanceProjectEvent::query()->where('project_id', $this->project->id)->where('type', 'like', 'workroom.%')->orderBy('id')->get();
        $this->assertSame([
            'workroom.file.uploaded', 'workroom.file.version', 'workroom.file.updated', 'workroom.comment.created', 'workroom.comment.updated', 'workroom.comment.deleted', 'workroom.file.deleted',
        ], $rows->pluck('type')->all());
        $this->assertSame(['client'], $rows->pluck('actor_type')->unique()->values()->all());
        $this->assertSame($this->owner->id, (int) $rows->first()->actor_id);
        $this->assertSame($file, $rows[0]->payload['file']);
        $this->assertSame(2, $rows[1]->payload['version']);
        $this->assertSame(['title'], $rows[2]->payload['changed']);
        $this->assertSame($note, $rows[3]->payload['comment']);
        $this->assertSame(2, $rows[3]->payload['version']);
        // The text of a note never goes on the record.
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('secret wording', json_encode($row->payload));
        }

        Event::assertDispatchedTimes(FreelanceWorkroomChanged::class, 7);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'file' && $e->action === 'version' && $e->uuid === $file);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'comment' && $e->action === 'deleted' && $e->uuid === $file);
        Event::assertDispatched(FreelanceWorkroomChanged::class, fn (FreelanceWorkroomChanged $e) => $e->broadcastWith()['kind'] === 'file' && $e->action === 'deleted');
    }

    public function test_a_refused_step_tells_nobody_and_writes_nothing(): void
    {
        $this->active();
        $file = $this->freelancerFile();
        Event::fake([FreelanceWorkroomChanged::class]);
        $records = FreelanceProjectEvent::query()->count();
        $told = $this->told($this->owner, 'freelance.workroom.file_shared');

        $this->as($this->owner);
        $this->putJson($this->files(self::CLIENT, "/{$file}"), ['title' => 'Mine'])->assertForbidden();
        $this->deleteJson($this->files(self::CLIENT, "/{$file}"))->assertForbidden();
        $this->postJson($this->files(self::CLIENT, "/{$file}/versions"), ['file' => $this->pdf('mine.pdf')])->assertForbidden();
        $this->postJson($this->files(self::CLIENT), ['file' => UploadedFile::fake()->create('run.exe', 10, 'application/x-msdownload')])->assertUnprocessable();
        $this->postJson($this->notes(self::CLIENT), ['file' => $file, 'body' => '   '])->assertUnprocessable();

        Event::assertNotDispatched(FreelanceWorkroomChanged::class);
        $this->assertSame($records, FreelanceProjectEvent::query()->count());
        $this->assertSame($told, $this->told($this->owner, 'freelance.workroom.file_shared'));
        $this->assertSame(0, $this->told($this->sara, 'freelance.workroom.file_shared'));
    }
}
