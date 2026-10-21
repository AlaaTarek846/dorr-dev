<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Account\FreelancerCompleteness;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Tests\TestCase;

/** FL-103: the freelancer's own profile — headline, bio, languages, the email confirmed by a code, and how complete it is. */
class FreelancerProfileTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'sa']);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['ar', 'en', 'fr'] as $code) {
            Language::create(['code' => $code, 'direction' => $code === 'ar' ? 'rtl' : 'ltr', 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => $code !== 'fr', 'flag_id' => $flag->id]);
        }
        FreelanceFeatureRule::query()->create(['feature' => 'freelance', 'country_id' => $this->saudi->id, 'enabled' => true]);
        FreelanceFeatures::forget();
        config(['auth_flow.email_otp_fixed' => '4321']);
        Mail::fake();

        $this->me = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000077', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'registered']);
        Sanctum::actingAs($this->me, [], 'freelance_api');
    }

    private function profile(array $extra = []): array
    {
        return ['name' => 'Sara Hassan'] + $extra;
    }

    public function test_the_new_profile_fields_are_saved_and_returned(): void
    {
        $this->putJson('/api/freelance/v1/profile', $this->profile([
            'headline' => 'Laravel & Vue developer', 'bio' => 'Ten years building web apps for clients across the region.',
            'years_of_experience' => 10, 'contact_languages' => ['ar', 'en'], 'city' => 'Jeddah',
        ]))->assertOk()
            ->assertJsonPath('data.headline', 'Laravel & Vue developer')
            ->assertJsonPath('data.years_of_experience', 10)
            ->assertJsonPath('data.contact_languages', ['ar', 'en'])
            ->assertJsonPath('data.city', 'Jeddah')
            ->assertJsonPath('data.email_verified', false);

        // A field not sent stays; one sent empty is cleared.
        $this->putJson('/api/freelance/v1/profile', $this->profile(['city' => null]))->assertOk()
            ->assertJsonPath('data.headline', 'Laravel & Vue developer')->assertJsonPath('data.city', null);
    }

    public function test_bad_profile_values_are_refused(): void
    {
        foreach ([
            ['headline' => 'ab'], ['headline' => str_repeat('x', 101)], ['bio' => str_repeat('x', 1501)],
            ['years_of_experience' => 61], ['years_of_experience' => -1],
            ['contact_languages' => ['xx']], ['contact_languages' => ['fr']] /* switched off */, ['contact_languages' => ['en', 'en']],
            ['contact_languages' => array_fill(0, 11, 'en')],
        ] as $bad) {
            $this->putJson('/api/freelance/v1/profile', $this->profile($bad))->assertUnprocessable();
        }
    }

    public function test_the_email_is_not_changed_by_the_profile_only_by_a_code(): void
    {
        $this->putJson('/api/freelance/v1/profile', $this->profile(['email' => 'sara@example.com']))->assertOk()->assertJsonPath('data.email', null);
        $this->assertNull($this->me->fresh()->email);
    }

    public function test_the_email_is_confirmed_with_a_code_sent_to_it(): void
    {
        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'sara@example.com'])->assertOk()->assertJsonPath('data.masked_email', 'sa**@example.com');
        Mail::assertSent(VerificationCodeMail::class, fn ($m) => $m->hasTo('sara@example.com'));
        $this->assertNull($this->me->fresh()->email, 'nothing changes before the code');

        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '0000'])->assertUnprocessable();
        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertOk()
            ->assertJsonPath('data.email', 'sara@example.com')->assertJsonPath('data.email_verified', true);
        $this->assertNotNull($this->me->fresh()->email_verified_at);
    }

    public function test_confirming_without_asking_for_a_code_or_after_it_expired_fails(): void
    {
        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertUnprocessable();

        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'sara@example.com'])->assertOk();
        Cache::forget('freelancer:email-change:'.$this->me->id);
        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertUnprocessable();
    }

    public function test_an_email_another_freelancer_has_is_refused(): void
    {
        Freelancer::query()->create(['phone' => '+966500000078', 'email' => 'taken@example.com', 'country_id' => $this->saudi->id]);

        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'taken@example.com'])->assertUnprocessable();
        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'not-an-email'])->assertUnprocessable();
        Mail::assertNothingSent();
    }

    public function test_an_address_taken_between_the_code_and_the_confirmation_is_refused(): void
    {
        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'sara@example.com'])->assertOk();
        Freelancer::query()->create(['phone' => '+966500000078', 'email' => 'sara@example.com', 'country_id' => $this->saudi->id]);

        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertUnprocessable();
        $this->assertNull($this->me->fresh()->email);
    }

    public function test_changing_the_email_needs_a_new_code_and_the_old_one_stays_until_then(): void
    {
        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'sara@example.com'])->assertOk();
        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertOk();
        Cache::flush(); // the resend cooldown

        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'new@example.com'])->assertOk();
        $this->assertSame('sara@example.com', $this->me->fresh()->email);
        $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertOk()->assertJsonPath('data.email', 'new@example.com');
    }

    public function test_completeness_counts_each_step_and_says_what_is_missing(): void
    {
        Storage::fake('public');
        $first = $this->getJson('/api/freelance/v1/auth/me')->assertOk()->json('data.completeness');
        $this->assertSame(10, $first['percent'], 'only the name is filled in');
        $this->assertEqualsCanonicalizing(['avatar', 'headline', 'bio', 'email', 'languages', 'experience', 'specialties', 'skills', 'work', 'portfolio'], $first['missing']);

        $this->putJson('/api/freelance/v1/profile', $this->profile(['headline' => 'Designer', 'bio' => 'Short bio', 'years_of_experience' => 3, 'contact_languages' => ['ar']]))->assertOk();
        $short = $this->getJson('/api/freelance/v1/auth/me')->json('data.completeness');
        $this->assertContains('bio', $short['missing'], 'a bio under '.FreelancerCompleteness::BIO_MIN.' characters does not count');
        $this->assertSame(10 + 10 + 5 + 5, $short['percent']);

        $this->putJson('/api/freelance/v1/profile', $this->profile(['bio' => str_repeat('A sentence about the work I do. ', 3)]))->assertOk();
        $this->postJson('/api/freelance/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])->assertOk();
        $this->postJson('/api/freelance/v1/profile/email/send-code', ['email' => 'sara@example.com'])->assertOk();
        $done = $this->postJson('/api/freelance/v1/profile/email/confirm', ['code' => '4321'])->assertOk()->json('data.completeness');

        // What the profile lists (specialties, skills, work) is FL-104's: everything else adds up to 60%.
        $this->assertSame(60, $done['percent']);
        $this->assertEqualsCanonicalizing(['specialties', 'skills', 'work', 'portfolio'], $done['missing']);
        $this->assertSame('registered', $this->me->fresh()->status->value, 'a full bar does not move the account on by itself (the terms and verification do)');
    }

    public function test_the_currency_is_not_a_profile_field(): void
    {
        $this->putJson('/api/freelance/v1/profile', $this->profile(['currency' => 'USD']))->assertOk()->assertJsonMissingPath('data.currency');
    }
}
