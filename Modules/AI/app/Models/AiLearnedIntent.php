<?php

namespace Modules\AI\Models;

use App\Traits\SearchFilterTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AI\Models\Concerns\HasIsActiveAlias;
use Modules\AI\Services\AiLearnedIntentStore;
use Modules\AI\Support\ArabicTextNormalizer;

/**
 * One phrase of the smart intent router's learned dictionary (AiLearnedIntentStore / AiIntentRouterService).
 * Has the countries list (status, trash) but no translation table: the phrase is the users' own words the router
 * matches, and the intent / mode / format are technical codes.
 *
 * Only switched-on rows that are not in the trash match. A trashed row is never switched back on by the router.
 * Every change empties the store's cache of active rows, so it takes effect on the next message.
 * `$row->is_active` reads `status` (HasIsActiveAlias).
 */
class AiLearnedIntent extends Model
{
    use HasIsActiveAlias, SearchFilterTrait, SoftDeletes;

    public const MODE_PHRASE = 'phrase';

    public const MODE_EXACT = 'exact';

    public const SOURCE_MODEL = 'model';

    public const SOURCE_ADMIN = 'admin';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'phrase', 'match_mode', 'intent', 'file_format', 'language', 'confidence', 'confirmations',
        'conflicts', 'hits', 'last_hit_at', 'status', 'is_active', 'source', 'learned_with_model',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'confidence' => 'float',
            'last_hit_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $flush = fn () => app(AiLearnedIntentStore::class)->flushCache();

        static::saved($flush);
        static::deleted($flush);
        static::restored($flush);
    }

    /** active | pending (waiting for confirmations) | conflict (contradicted by the model, or switched off by an admin). */
    public function state(): string
    {
        return match (true) {
            $this->conflicts > 0 => 'conflict',
            (bool) $this->status => 'active',
            default => 'pending',
        };
    }

    /**
     * The list search matches the stored (normalized) phrase, so the typed text is normalized the same way
     * (ى/ي, ة/ه, diacritics…).
     */
    protected function decodeSearchPayload(): ?object
    {
        $search = request()->search;
        $payload = is_array($search) ? (object) $search : (is_string($search) && $search !== '' ? json_decode($search) : null);

        if (is_object($payload) && isset($payload->searchKey) && is_string($payload->searchKey) && $payload->searchKey !== '') {
            $payload->searchKey = ArabicTextNormalizer::normalize($payload->searchKey);
        }

        return $payload;
    }
}
