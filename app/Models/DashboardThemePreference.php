<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DashboardThemePreference extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dashboard_theme_id',
        'authenticatable_type',
        'authenticatable_id',
    ];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(DashboardTheme::class, 'dashboard_theme_id');
    }

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }
}
