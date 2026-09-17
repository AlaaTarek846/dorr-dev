<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardThemeTranslation extends Model
{
    protected $fillable = [
        'dashboard_theme_id',
        'locale',
        'name',
    ];

    public function dashboardTheme(): BelongsTo
    {
        return $this->belongsTo(DashboardTheme::class);
    }
}
