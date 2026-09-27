<?php

namespace Modules\Wallet\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Enums\PinRecoveryStatus;
use Modules\Wallet\Enums\RecoveryMethod;
use Modules\Wallet\Support\OwnerType;
use Spatie\MediaLibrary\HasMedia;

/**
 * "I forgot my wallet PIN" made with a document. Two private photos are kept side by side for the
 * reviewer: `original_document` (a copy of what the owner uploaded when creating the PIN — copied
 * at request time, so changing the recovery method later can't erase the evidence) and
 * `new_document` (what they just uploaded).
 */
class PinRecoveryRequest extends Model implements HasMedia
{
    use HasMediaTrait;

    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'method', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return [
            'method' => RecoveryMethod::class,
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
