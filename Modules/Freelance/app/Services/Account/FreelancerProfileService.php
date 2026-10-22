<?php

namespace Modules\Freelance\Services\Account;

use App\Enums\VerificationType;
use App\Services\Auth\VerificationCodeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Enums\FreelancerStatus;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;

/**
 * The freelancer's own account (FL-003): completing the profile, the avatar, the terms and deleting
 * the account. A profile that is complete with the current terms accepted moves the account from
 * `registered` to `profile_ready`; verification itself is FL-107 / FL-108 (FreelancerVerification).
 */
class FreelancerProfileService
{
    private const PENDING_EMAIL = 'freelancer:email-change:';

    public function __construct(
        private readonly FreelancerAuthService $auth,
        private readonly FreelanceFeatures $features,
        private readonly VerificationCodeService $codes,
    ) {}

    /** @return array<string, mixed> */
    public static function profileRules(Freelancer $freelancer): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'headline' => ['nullable', 'string', 'min:3', 'max:100'],
            'bio' => ['nullable', 'string', 'max:1500'],
            'years_of_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            'contact_languages' => ['nullable', 'array', 'max:10'],
            'contact_languages.*' => ['string', 'distinct', Rule::exists('languages', 'code')->where('status', true)],
            'city' => ['nullable', 'string', 'max:80'],
            'residency' => ['nullable', Rule::in(['citizen', 'resident'])],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'date_of_birth' => ['nullable', 'date', 'before:-16 years'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')->where('status', true)],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ];
    }

    /** @param array<string, mixed> $data */
    public function update(Freelancer $freelancer, array $data): Freelancer
    {
        if (isset($data['country_id']) && (int) $data['country_id'] !== $freelancer->country_id
            && ! $this->features->allows((int) $data['country_id'], FreelanceFeatures::SERVICE)) {
            throw new FreelanceException('not_available', 403);
        }

        // Fields that were sent are saved as sent (an empty one clears it); the others stay. The email is not here:
        // it changes only through a code sent to it (requestEmailChange / confirmEmailChange).
        $freelancer->fill(array_intersect_key($data, array_flip(['name', 'headline', 'bio', 'years_of_experience', 'contact_languages', 'city', 'residency', 'gender', 'date_of_birth', 'country_id', 'locale'])));
        $freelancer->save();

        return $this->advance($freelancer);
    }

    /** Step 1: a code goes to the new address; nothing on the profile changes until it is confirmed. */
    public function requestEmailChange(Freelancer $freelancer, string $email): array
    {
        $this->codes->send($freelancer, VerificationType::Email, $email);
        Cache::put(self::PENDING_EMAIL.$freelancer->getKey(), $email, now()->addMinutes((int) config('auth_flow.otp_expiry_minutes', 10)));

        return ['masked_email' => $this->codes->maskEmail($email), 'resend_cooldown_seconds' => (int) config('auth_flow.otp_resend_cooldown_seconds', 60)];
    }

    /** Step 2: the right code makes the pending address the freelancer's verified email. */
    public function confirmEmailChange(Freelancer $freelancer, string $code): Freelancer
    {
        $pending = Cache::get(self::PENDING_EMAIL.$freelancer->getKey());
        if (! is_string($pending) || $pending === '') {
            throw ValidationException::withMessages(['code' => [__('api.change_request_expired')]]);
        }
        // Someone may have taken the address in the meantime.
        if (Freelancer::query()->where('email', $pending)->whereKeyNot($freelancer->id)->exists()) {
            throw ValidationException::withMessages(['email' => [__('validation.unique', ['attribute' => 'email'])]]);
        }

        $this->codes->verify($freelancer, VerificationType::Email, $code);
        $freelancer->forceFill(['email' => $pending, 'email_verified_at' => now()])->save();
        Cache::forget(self::PENDING_EMAIL.$freelancer->getKey());

        return $freelancer->refresh();
    }

    public function avatar(Freelancer $freelancer, UploadedFile $file): Freelancer
    {
        $freelancer->setSingleMedia('avatar', $file);

        return $freelancer->refresh();
    }

    public function acceptTerms(Freelancer $freelancer, string $version): Freelancer
    {
        if ($version !== (string) config('freelance.terms_version')) {
            throw new FreelanceException('terms_outdated', 422, [], ['current_version' => (string) config('freelance.terms_version')]);
        }

        $freelancer->forceFill(['terms_accepted_at' => now(), 'terms_version' => $version])->save();

        return $this->advance($freelancer);
    }

    /**
     * The account goes, the records that point at it stay (projects, payments, disputes later):
     * soft delete with the personal data wiped and every session ended.
     */
    public function delete(Freelancer $freelancer): void
    {
        DB::transaction(function () use ($freelancer): void {
            $this->auth->endAllSessions($freelancer);
            $freelancer->verificationCodes()->delete();
            $freelancer->clearMediaCollection('avatar');
            app(FreelancerProfileLists::class)->clearAll($freelancer);
            $freelancer->forceFill([
                'name' => null,
                'phone' => 'deleted-'.$freelancer->id,
                'email' => null,
                'gender' => null,
                'date_of_birth' => null,
            ])->save();
            $freelancer->delete();
        });
    }

    /** What the profile still needs before the account can wait for verification. */
    public function missing(Freelancer $freelancer): array
    {
        return array_values(array_filter([
            blank($freelancer->name) ? 'name' : null,
            $freelancer->hasAcceptedTerms() ? null : 'terms',
        ]));
    }

    private function advance(Freelancer $freelancer): Freelancer
    {
        if ($freelancer->status === FreelancerStatus::Registered && $this->missing($freelancer) === []) {
            $freelancer->forceFill(['status' => FreelancerStatus::ProfileReady])->save();
        }

        return $freelancer->refresh();
    }
}
