<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

class AiDomainPolicy extends Model
{
    public const DOMAIN_LEGAL = 'legal';

    public const DOMAIN_HEALTH = 'health';

    public const DOMAIN_EDUCATION = 'education';

    public const DOMAIN_CODE = 'code';

    public const DOMAIN_MARKETING = 'marketing';

    public const DOMAIN_GENERAL_INFO = 'general_info';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain_key',
        'name',
        'description',
        'risk_level',
        'requires_jurisdiction',
        'requires_triage',
        'sandbox_required',
        'allowlist_enforced',
        'system_prompt_addition',
        'disclaimer_text',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_jurisdiction' => 'boolean',
            'requires_triage' => 'boolean',
            'sandbox_required' => 'boolean',
            'allowlist_enforced' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
