<?php

namespace Modules\Wallet\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Enums\PinRecoveryReason;
use Modules\Wallet\Enums\PinRecoveryStatus;
use Modules\Wallet\Enums\RecoveryMethod;
use Modules\Wallet\Support\OwnerType;
use Spatie\MediaLibrary\HasMedia;

/**
 * A request that needs a person to look at two photos and decide. Two different situations share this
 * one model/table/admin screen — {@see reason}:
 *
 *  - `RecoveryDocument`: "I forgot my PIN" via the ID/passport photo chosen as the recovery method.
 *    `original_document` is a copy of what the owner uploaded when creating the PIN (copied at request
 *    time, so changing the recovery method later can't erase the evidence); `new_document` is what
 *    they just uploaded.
 *  - `SecurityFreeze`: the PIN got permanently frozen (wrong attempt right after a temporary lock).
 *    `original_document` is an ID/passport photo taken *now* (not from setup — the owner's configured
 *    method may not even be a document one); `new_document` is a selfie.
 */
class PinRecoveryRequest extends Model implements HasMedia
{
    use HasMediaTrait;

    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'method', 'reason', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'method' => RecoveryMethod::class,
            'reason' => PinRecoveryReason::class,
            'status' => PinRecoveryStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('original_document')->singleFile()->useDisk('local');
        $this->addMediaCollection('new_document')->singleFile()->useDisk('local');
    }

    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }
}
