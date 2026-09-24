<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiDataPolicy extends Model
{
    use HasFactory;

    const CLASSIFICATION_PUBLIC = 'public';

    const CLASSIFICATION_INTERNAL = 'internal';

    const CLASSIFICATION_CONFIDENTIAL = 'confidential';

    const CLASSIFICATION_PERSONAL = 'personal';

    const CLASSIFICATION_SECRET = 'secret';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'data_classification',
        'retention_days',
        'consent_required',
        'minimization_enabled',
        'external_provider_allowed',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_days' => 'integer',
            'consent_required' => 'boolean',
            'minimization_enabled' => 'boolean',
            'external_provider_allowed' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
