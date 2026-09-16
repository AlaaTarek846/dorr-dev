<?php

namespace Modules\Provider\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Admin\Models\Admin;
use Modules\Provider\Enums\ProviderStatus;
use Modules\User\Models\User;

class ProviderProfile extends Model
{
    use SearchFilterTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'business_name',
        'national_id',
        'commercial_register_no',
        'id_document_path',
        'license_document_path',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function services(): HasMany
    {
        return $this->hasMany(ProviderService::class);
    }

    public function isApproved(): bool
    {
        return $this->status === ProviderStatus::Approved;
    }
}
