<?php

namespace App\Services\General;

use App\Http\Resources\General\ReferralCodeResource;
use App\Models\ReferralCode;
use App\Repositories\General\ReferralCodeRepository;
use App\Services\CatalogService;
use App\Services\Referral\ReferralCodeGenerator;
use App\Support\Referral\ReferrableType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReferralCodeService extends CatalogService
{
    protected ?string $resource = ReferralCodeResource::class;

    public function __construct(
        ReferralCodeRepository $repository,
        protected ReferralCodeGenerator $generator,
    ) {
        parent::__construct($repository);
    }

    public function codeFor(Model $owner): ReferralCode
    {
        ReferrableType::aliasFor($owner);

        $existing = $this->activeFor($owner);
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($owner) {
            $existing = $this->activeFor($owner);
            if ($existing !== null) {
                return $existing;
            }

            $type = ReferrableType::aliasFor($owner);

            for ($attempt = 0; $attempt < 15; $attempt++) {
                try {
                    return ReferralCode::query()->create([
                        'code' => $this->generator->make(),
                        'referrable_type' => $type,
                        'referrable_id' => $owner->getKey(),
                        'is_active' => true,
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue;
                }
            }

            throw new RuntimeException('Could not generate a unique referral code.');
        });
    }

    public function activeFor(Model $owner): ?ReferralCode
    {
        return ReferralCode::query()
            ->where('referrable_type', ReferrableType::aliasFor($owner))
            ->where('referrable_id', $owner->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }
}
