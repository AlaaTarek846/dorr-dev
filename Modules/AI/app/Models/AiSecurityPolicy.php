<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiSecurityPolicy extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'authentication_required',
        'authorization_required',
        'tenant_isolation_required',
        'rate_limit_enabled',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authentication_required' => 'boolean',
            'authorization_required' => 'boolean',
            'tenant_isolation_required' => 'boolean',
            'rate_limit_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
