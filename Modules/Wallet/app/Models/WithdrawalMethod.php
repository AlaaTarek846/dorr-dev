<?php

namespace Modules\Wallet\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Wallet\Enums\WithdrawalMethodType;
use Modules\Wallet\Support\OwnerType;

/**
 * Where an owner wants a withdrawal paid out. `data` (IBAN, wallet number…) is
 * encrypted at rest and never sent back in full to the owner — only the masked
 * {@see self::maskedDisplay()}; an admin sees the full details on the request
 * they must pay.
 */
class WithdrawalMethod extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['owner_type', 'owner_id', 'type', 'label', 'data', 'is_favorite', 'status'];

    /**
     * @var list<string>
     */
    protected $hidden = ['data'];

    protected function casts(): array
    {
        return [
            'type' => WithdrawalMethodType::class,
            'data' => 'encrypted:array',
            'is_favorite' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function owner(): ?Model
    {
        return OwnerType::modelClassFor($this->owner_type)::query()->find($this->owner_id);
    }

    /**
     * "Al Rajhi ••1234" / "STC Pay ••5678" — enough to recognise, not to use.
     */
    public function maskedDisplay(): string
    {
        $data = $this->data ?? [];

        [$name, $number] = $this->type === WithdrawalMethodType::Bank
            ? [$data['bank_name'] ?? '', $data['iban'] ?? '']
            : [$data['provider_name'] ?? '', $data['number'] ?? ''];

        return trim($name.' ••'.substr((string) $number, -4));
    }
}
