<?php

namespace App\Services\General;

use App\Enums\ReferralStatus;
use App\Http\Resources\General\ReferralResource;
use App\Models\Referral;
use App\Models\ReferralCode;
use App\Repositories\General\ReferralRepository;
use App\Services\BaseService;
use App\Services\Referral\ReferralCodeGenerator;
use App\Support\Referral\ReferrableType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Modules\User\Models\User;

class ReferralService extends BaseService
{
    protected ?string $resource = ReferralResource::class;

    public function __construct(ReferralRepository $repository)
    {
        parent::__construct($repository);
    }

    public function track(User $user, string $rawCode): Referral
    {
        $normalized = ReferralCodeGenerator::normalize($rawCode);

        if (! ReferralCodeGenerator::isValidFormat($normalized)) {
            throw $this->invalidCode();
        }

        $code = ReferralCode::query()->where('code', $normalized)->first();
        if ($code === null) {
            throw $this->invalidCode();
        }

        if (! $code->is_active) {
            throw ValidationException::withMessages([
                'referral_code' => [__('api.referral_code_inactive')],
            ]);
        }

        $referredType = ReferrableType::aliasFor($user);
        $referredId = (int) $user->getKey();

        if ($code->referrable_type === $referredType && (int) $code->referrable_id === $referredId) {
            throw ValidationException::withMessages([
                'referral_code' => [__('api.referral_self')],
            ]);
        }

        $existing = Referral::query()
            ->where('referred_type', $referredType)
            ->where('referred_id', $referredId)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->referral_code_id === (int) $code->id) {
                return $existing;
            }

            throw ValidationException::withMessages([
                'referral_code' => [__('api.referral_already_applied')],
            ]);
        }

        try {
            return Referral::query()->create([
                'referrer_type' => $code->referrable_type,
                'referrer_id' => $code->referrable_id,
                'referred_type' => $referredType,
                'referred_id' => $referredId,
                'referral_code_id' => $code->id,
                'status' => ReferralStatus::Registered,
                'registered_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $again = Referral::query()
                ->where('referred_type', $referredType)
                ->where('referred_id', $referredId)
                ->first();

            if ($again !== null && (int) $again->referral_code_id === (int) $code->id) {
                return $again;
            }

            throw ValidationException::withMessages([
                'referral_code' => [__('api.referral_already_applied')],
            ]);
        }
    }

    public function appliedBy(User $user): ?Referral
    {
        return Referral::query()
            ->with('referralCode')
            ->where('referred_type', ReferrableType::aliasFor($user))
            ->where('referred_id', $user->getKey())
            ->first();
    }

    public function markCompleted(Referral $referral): Referral
    {
        if ($referral->status === ReferralStatus::Cancelled) {
            throw ValidationException::withMessages([
                'status' => [__('api.referral_cancelled')],
            ]);
        }

        $referral->update([
            'status' => ReferralStatus::Completed,
            'completed_at' => $referral->completed_at ?? now(),
        ]);

        return $referral->refresh();
    }

    private function invalidCode(): ValidationException
    {
        return ValidationException::withMessages([
            'referral_code' => [__('api.referral_code_invalid')],
        ]);
    }
}
