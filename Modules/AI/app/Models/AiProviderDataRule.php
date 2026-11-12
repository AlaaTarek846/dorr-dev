<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;

/**
 * One provider's data rule: clean personal data / secrets out of the user's turns before a call leaves to that provider
 * (AiGateway::applyDataRules). Has the countries list (status, trash) but no translation table: it holds no text people
 * read - a provider, two switches and JSON transformation rules.
 *
 * One rule per provider (unique provider_id, the trash included). Switched off or in the trash = not applied (the
 * provider gets the text as it is). `$rule->is_active` reads `status` (HasIsActiveAlias).
 */
class AiProviderDataRule extends Model
{
    use HasFactory, HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'provider_id',
        'sanitize_pii',
        'sanitize_secrets',
        'transformation_rules',
        'status',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sanitize_pii' => 'boolean',
            'sanitize_secrets' => 'boolean',
            'transformation_rules' => 'array',
            'status' => 'boolean',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'provider_id');
    }
}
