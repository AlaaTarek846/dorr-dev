<?php

namespace Modules\Sports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the provider's sports (football, basketball…), on or off. */
class SportsSport extends Model
{
    protected $table = 'sports_sports';

    protected $fillable = ['key', 'status', 'min_share_percent', 'sort_order'];

    protected function casts(): array
    {
        return ['status' => 'boolean', 'min_share_percent' => 'integer', 'sort_order' => 'integer'];
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(SportsCompetition::class, 'sport_id');
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return (array) config('sports.sports.'.$this->key, []);
    }

    public function emoji(): string
    {
        return (string) ($this->config()['emoji'] ?? '🏆');
    }

    public function name(): string
    {
        return (string) __('sports.names.'.$this->key);
    }
}
