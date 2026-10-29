<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatFeatureRule;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * A group's kind (docs/groups_tasks.md GR-001/002): normal, education or work — picked when the
 * group is made, never changed; each kind on/off (admin switch, rules per country) and a limit on
 * how many one person owns.
 */
class ChatGroupKindsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private User $teacher;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->teacher = $make('Teacher', '+966500000001');
        $this->student = $make('Student', '+966500000002');
        Sanctum::actingAs($this->teacher, [], 'user_api');
    }

    public function test_a_group_gets_its_kind_when_made_and_keeps_it(): void
    {
        // Ordinary by default, as before.
        $this->create(null)->assertCreated()->assertJsonPath('data.conversation.group.kind', 'normal');

        $class = $this->create('education')->assertCreated()->assertJsonPath('data.conversation.group.kind', 'education')->json('data.conversation.id');
        $this->create('work')->assertCreated()->assertJsonPath('data.conversation.group.kind', 'work');
        $this->create('school')->assertUnprocessable()->assertJsonValidationErrors('kind');

        // Never changed afterwards.
        $this->postJson("/api/mobile/v1/chat/groups/{$class}", ['name' => 'Maths 3', 'kind' => 'work'], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/conversations/{$class}", $this->headers())->assertOk()
            ->assertJsonPath('data.group.kind', 'education')->assertJsonPath('data.group.name', 'Maths 3');

        // The app learns which kinds it may offer.
        $this->getJson('/api/mobile/v1/chat/features', $this->headers())->assertOk()
            ->assertJsonPath('data.features.education_groups', true)->assertJsonPath('data.features.work_groups', true);
    }

    public function test_a_kind_switched_off_by_the_admin_or_in_a_country_cannot_be_made(): void
    {
        ChatSetting::current()->update(['education_groups_enabled' => false]);
        $this->create('education')->assertForbidden()->assertJsonPath('error_code', 'chat_group_kind_disabled');
        $this->getJson('/api/mobile/v1/chat/features', $this->headers())->assertJsonPath('data.features.education_groups', false);

        ChatFeatureRule::query()->create(['feature' => 'work_groups', 'country_id' => $this->saudi->id, 'enabled' => false]);
        $this->create('work')->assertForbidden()->assertJsonPath('error_code', 'chat_group_kind_disabled');

        // Ordinary groups are never affected.
        $this->create(null)->assertCreated();
    }

    public function test_one_person_owns_at_most_the_limit_of_each_kind(): void
    {
        ChatSetting::current()->update(['max_owned_kind_groups' => 1]);

        $this->create('education')->assertCreated();
        $this->create('education')->assertStatus(422)->assertJsonPath('error_code', 'chat_group_limit_reached');
        // Per kind, and ordinary groups don't count.
        $this->create('work')->assertCreated();
        $this->create(null)->assertCreated();
        $this->create(null)->assertCreated();
    }

    private function create(?string $kind)
    {
        return $this->postJson('/api/mobile/v1/chat/groups', array_filter([
            'name' => 'Maths',
            'members' => [$this->student->id],
            'kind' => $kind,
        ]), $this->headers());
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
