<?php

namespace Modules\Wallet\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Modules\Wallet\Enums\RecoveryMethod;
use Modules\Wallet\Support\OwnerType;
use Spatie\MediaLibrary\HasMedia;

/**
 * The recovery method an owner picked when creating their wallet PIN. The ID / passport photo lives
 * in a *private* media collection (it is a personal document), and `secret_hash` never leaves the
 * server in any form.
 */
class WalletRecoveryMethod extends Model implements HasMedia
{
    use HasMediaTrait;

    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'method', 'secret_hash', 'email', 'email_verified_at', 'pending_email', 'failed_attempts', 'locked_until'];

    protected $hidden = ['secret_hash'];

    protected function casts(): array
    {
        return [
            'method' => RecoveryMethod::class,
            'email_verified_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_attempts' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('document')->singleFile()->useDisk('local');
    }

    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    /**
     * Can it be used to recover a PIN? An e-mail must be confirmed first; a document must be there.
     */
    public function isReady(): bool
    {
        return match ($this->method) {
            RecoveryMethod::Password, RecoveryMethod::BirthDate => $this->secret_hash !== null,
            RecoveryMethod::IdPhoto, RecoveryMethod::PassportPhoto => $this->getFirstMedia('document') !== null,
            RecoveryMethod::Email => $this->email !== null && $this->email_verified_at !== null,
        };
    }
}
